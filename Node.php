<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Ldap
 * @subpackage Node
 * @copyright  Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */

namespace Zend\Ldap;

/**
 * Zend\Ldap\Node provides an object oriented view into a LDAP node.
 *
 * @category   Zend
 * @package    Zend_Ldap
 * @subpackage Node
 * @copyright  Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Node extends Node\AbstractNode implements \Iterator, \RecursiveIterator
{
    /**
     * Holds the node's new Dn if node is renamed.
     *
     * @var Dn
     */
    protected $newDn;

    /**
     * Holds the node's orginal attributes (as loaded).
     *
     * @var array
     */
    protected $originalData;

    /**
     * This node will be added
     *
     * @var boolean
     */
    protected $new;

    /**
     * This node will be deleted
     *
     * @var boolean
     */

    protected $delete;
    /**
     * Holds the connection to the LDAP server if in connected mode.
     *
     * @var Ldap
     */
    protected $ldap;

    /**
     * Holds an array of the current node's children.
     *
     * @var array
     */
    protected $children;

    /**
     * Controls iteration status
     *
     * @var boolean
     */
    private $iteratorRewind = false;

    /**
     * Constructor.
     *
     * Constructor is protected to enforce the use of factory methods.
     *
     * @param  Dn      $dn
     * @param  array   $data
     * @param  boolean $fromDataSource
     * @param  Ldap    $ldap
     * @throws Exception\LdapException
     */
    protected function __construct(Dn $dn, array $data, $fromDataSource, Ldap $ldap = null)
    {
        parent::__construct($dn, $data, $fromDataSource);
        if ($ldap !== null) $this->attachLdap($ldap);
        else $this->detachLdap();
    }

    /**
     * Serialization callback
     *
     * Only Dn and attributes will be serialized.
     *
     * @return array
     */
    public function __sleep()
    {
        return array('dn', 'currentData', 'newDn', 'originalData',
            'new', 'delete', 'children');
    }

    /**
     * Deserialization callback
     *
     * Enforces a detached node.
     *
     * @return null
     */
    public function __wakeup()
    {
        $this->detachLdap();
    }

    /**
     * Gets the current LDAP connection.
     *
     * @return Ldap
     * @throws Exception\LdapException
     */
    public function getLdap()
    {
        if ($this->ldap === null) {
            throw new Exception\LdapException(null, 'No LDAP connection specified.', Exception\LdapException::LDAP_OTHER);
        }
        else return $this->ldap;
    }

    /**
     * Attach node to an LDAP connection
     *
     * This is an offline method.
     *
     * @param  Ldap $ldap
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function attachLdap(Ldap $ldap)
    {
        if (!Dn::isChildOf($this->_getDn(), $ldap->getBaseDn())) {
            throw new Exception\LdapException(null, 'LDAP connection is not responsible for given node.',
                Exception\LdapException::LDAP_OTHER);
        }

        if ($ldap !== $this->ldap) {
            $this->ldap = $ldap;
            if (is_array($this->children)) {
                foreach ($this->children as $child) {
                    $child->attachLdap($ldap);
                }
            }
        }
        return $this;
    }

    /**
     * Detach node from LDAP connection
     *
     * This is an offline method.
     *
     * @return Node Provides a fluid interface
     */
    public function detachLdap()
    {
        $this->ldap = null;
        if (is_array($this->children)) {
            foreach ($this->children as $child) {
                $child->detachLdap();
            }
        }
        return $this;
    }

    /**
     * Checks if the current node is attached to a LDAP server.
     *
     * This is an offline method.
     *
     * @return boolean
     */
    public function isAttached()
    {
        return ($this->ldap !== null);
    }

    /**
     * @param  array   $data
     * @param  boolean $fromDataSource
     * @throws Exception\LdapException
     */
    protected function loadData(array $data, $fromDataSource)
    {
        parent::loadData($data, $fromDataSource);
        if ($fromDataSource === true) {
            $this->originalData = $data;
        } else {
            $this->originalData = array();
        }
        $this->children = null;
        $this->markAsNew(($fromDataSource === true) ? false : true);
        $this->markAsToBeDeleted(false);
    }

    /**
     * Factory method to create a new detached Zend\Ldap\Node for a given DN.
     *
     * @param  string|array|Dn $dn
     * @param  array           $objectClass
     * @return Node
     * @throws Exception\LdapException
     */
    public static function create($dn, array $objectClass = array())
    {
        if (is_string($dn) || is_array($dn)) {
            $dn = Dn::factory($dn);
        } else if ($dn instanceof Dn) {
            $dn = clone $dn;
        } else {
            throw new Exception\LdapException(null, '$dn is of a wrong data type.');
        }
        $new = new self($dn, array(), false, null);
        $new->ensureRdnAttributeValues();
        $new->setAttribute('objectClass', $objectClass);
        return $new;
    }

    /**
     * Factory method to create an attached Zend\Ldap\Node for a given DN.
     *
     * @param  string|array|Dn $dn
     * @param  Ldap            $ldap
     * @return Node|null
     * @throws Exception\LdapException
     */
    public static function fromLdap($dn, Ldap $ldap)
    {
        if (is_string($dn) || is_array($dn)) {
            $dn = Dn::factory($dn);
        } else if ($dn instanceof Dn) {
            $dn = clone $dn;
        } else {
            throw new Exception\LdapException(null, '$dn is of a wrong data type.');
        }
        $data = $ldap->getEntry($dn, array('*', '+'), true);
        if ($data === null) {
            return null;
        }
        $entry = new self($dn, $data, true, $ldap);
        return $entry;
    }

    /**
     * Factory method to create a detached Zend\Ldap\Node from array data.
     *
     * @param  array   $data
     * @param  boolean $fromDataSource
     * @return Node
     * @throws Exception\LdapException
     */
    public static function fromArray(array $data, $fromDataSource = false)
    {
        if (!array_key_exists('dn', $data)) {
            throw new Exception\LdapException(null, '\'dn\' key is missing in array.');
        }
        if (is_string($data['dn']) || is_array($data['dn'])) {
            $dn = Dn::factory($data['dn']);
        } else if ($data['dn'] instanceof Dn) {
            $dn = clone $data['dn'];
        } else {
            throw new Exception\LdapException(null, '\'dn\' key is of a wrong data type.');
        }
        $fromDataSource = ($fromDataSource === true) ? true : false;
        $new = new self($dn, $data, $fromDataSource, null);
        $new->ensureRdnAttributeValues();
        return $new;
    }

    /**
     * Ensures that teh RDN attributes are correctly set.
     *
     * @return void
     */
    protected function ensureRdnAttributeValues()
    {
        foreach ($this->getRdnArray() as $key => $value) {
            Attribute::setAttribute($this->currentData, $key, $value, false);
        }
    }

    /**
     * Marks this node as new.
     *
     * Node will be added (instead of updated) on calling update() if $new is true.
     *
     * @param boolean $new
     */
    protected function markAsNew($new)
    {
        $this->new = ($new === false) ? false : true;
    }

    /**
     * Tells if the node is consiedered as new (not present on the server)
     *
     * Please note, that this doesn't tell you if the node is present on the server.
     * Use {@link exits()} to see if a node is already there.
     *
     * @return boolean
     */
    public function isNew()
    {
        return $this->new;
    }

    /**
     * Marks this node as to be deleted.
     *
     * Node will be deleted on calling update() if $delete is true.
     *
     * @param boolean $delete
     */
    protected function markAsToBeDeleted($delete)
    {
        $this->delete = ($delete === true) ? true : false;
    }


    /**
    * Is this node going to be deleted once update() is called?
    *
    * @return boolean
    */
    public function willBeDeleted()
    {
        return $this->delete;
    }

    /**
     * Marks this node as to be deleted
     *
     * Node will be deleted on calling update() if $delete is true.
     *
     * @return Node Provides a fluid interface
     */
    public function delete()
    {
        $this->markAsToBeDeleted(true);
        return $this;
    }

    /**
    * Is this node going to be moved once update() is called?
    *
    * @return boolean
    */
    public function willBeMoved()
    {
        if ($this->isNew() || $this->willBeDeleted()) {
            return false;
        } else if ($this->newDn !== null) {
            return ($this->dn != $this->newDn);
        } else {
            return false;
        }
    }

    /**
     * Sends all pending changes to the LDAP server
     *
     * @param  Ldap $ldap
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function update(Ldap $ldap = null)
    {
        if ($ldap !== null) {
            $this->attachLdap($ldap);
        }
        $ldap = $this->getLdap();
        if (!($ldap instanceof Ldap)) {
            throw new Exception\LdapException(null, 'No LDAP connection available');
        }

        if ($this->willBeDeleted()) {
            if ($ldap->exists($this->dn)) {
                $ldap->delete($this->dn);
            }
            return $this;
        }

        if ($this->isNew()) {
            $data = $this->getData();
            $ldap->add($this->_getDn(), $data);
            $this->loadData($data, true);
            return $this;
        }

        $changedData = $this->getChangedData();
        if ($this->willBeMoved()) {
            $recursive = $this->hasChildren();
            $ldap->rename($this->dn, $this->newDn, $recursive, false);
            foreach ($this->newDn->getRdn() as $key => $value) {
                if (array_key_exists($key, $changedData)) {
                    unset($changedData[$key]);
                }
            }
            $this->dn = $this->newDn;
            $this->newDn = null;
        }
        if (count($changedData) > 0) {
            $ldap->update($this->_getDn(), $changedData);
        }
        $this->originalData = $this->currentData;
        return $this;
    }

    /**
     * Gets the DN of the current node as a Zend\Ldap\Dn.
     *
     * This is an offline method.
     *
     * @return Dn
     */
    protected function _getDn()
    {
        return ($this->newDn === null) ? parent::_getDn() : $this->newDn;
    }

    /**
     * Gets the current DN of the current node as a Zend\Ldap\Dn.
     * The method returns a clone of the node's DN to prohibit modification.
     *
     * This is an offline method.
     *
     * @return Dn
     */
    public function getCurrentDn()
    {
        $dn = clone parent::_getDn();
        return $dn;
    }

    /**
     * Sets the new DN for this node
     *
     * This is an offline method.
     *
     * @param  Dn|string|array $newDn
     * @throws Exception\LdapException
     * @return Node Provides a fluid interface
     */
    public function setDn($newDn)
    {
        if ($newDn instanceof Dn) {
            $this->newDn = clone $newDn;
        } else {
            $this->newDn = Dn::factory($newDn);
        }
        $this->ensureRdnAttributeValues();
        return $this;
    }

    /**
     * {@see setDn()}
     *
     * This is an offline method.
     *
     * @param  Dn|string|array $newDn
     * @throws Exception\LdapException
     * @return Node Provides a fluid interface
     */
    public function move($newDn)
    {
        return $this->setDn($newDn);
    }

    /**
     * {@see setDn()}
     *
     * This is an offline method.
     *
     * @param  Dn|string|array $newDn
     * @throws Exception\LdapException
     * @return Node Provides a fluid interface
     */
    public function rename($newDn)
    {
        return $this->setDn($newDn);
    }

    /**
     * Sets the objectClass.
     *
     * This is an offline method.
     *
     * @param  array|string $value
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function setObjectClass($value)
    {
        $this->setAttribute('objectClass', $value);
        return $this;
    }

    /**
     * Appends to the objectClass.
     *
     * This is an offline method.
     *
     * @param  array|string $value
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function appendObjectClass($value)
    {
        $this->appendToAttribute('objectClass', $value);
        return $this;
    }

    /**
     * Returns a LDIF representation of the current node
     *
     * @param  array $options Additional options used during encoding
     * @return string
     */
    public function toLdif(array $options = array())
    {
        $attributes = array_merge(array('dn' => $this->getDnString()), $this->getData(false));
        return Ldif\Encoder::encode($attributes, $options);
    }

    /**
     * Gets changed node data.
     *
     * The array contains all changed attributes.
     * This format can be used in {@link Zend\Ldap\Ldap::add()} and {@link Zend\Ldap\Ldap::update()}.
     *
     * This is an offline method.
     *
     * @return array
     */
    public function getChangedData()
    {
        $changed = array();
        foreach ($this->currentData as $key => $value) {
            if (!array_key_exists($key, $this->originalData) && !empty($value)) {
                $changed[$key] = $value;
            } else if ($this->originalData[$key] !== $this->currentData[$key]) {
                $changed[$key] = $value;
            }
        }
        return $changed;
    }

    /**
     * Returns all changes made.
     *
     * This is an offline method.
     *
     * @return array
     */
    public function getChanges()
    {
        $changes = array(
            'add'     => array(),
            'delete'  => array(),
            'replace' => array());
        foreach ($this->currentData as $key => $value) {
            if (!array_key_exists($key, $this->originalData) && !empty($value)) {
                $changes['add'][$key] = $value;
            } else if (count($this->originalData[$key]) === 0 && !empty($value)) {
                $changes['add'][$key] = $value;
            } else if ($this->originalData[$key] !== $this->currentData[$key]) {
                if (empty($value)) {
                    $changes['delete'][$key] = $value;
                } else {
                    $changes['replace'][$key] = $value;
                }
            }
        }
        return $changes;
    }

    /**
     * Sets a LDAP attribute.
     *
     * This is an offline method.
     *
     * @param  string $name
     * @param  mixed  $value
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function setAttribute($name, $value)
    {
        $this->_setAttribute($name, $value, false);
        return $this;
    }

    /**
     * Appends to a LDAP attribute.
     *
     * This is an offline method.
     *
     * @param  string $name
     * @param  mixed  $value
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function appendToAttribute($name, $value)
    {
        $this->_setAttribute($name, $value, true);
        return $this;
    }

    /**
     * Checks if the attribute can be set and sets it accordingly.
     *
     * @param  string  $name
     * @param  mixed   $value
     * @param  boolean $append
     * @throws Exception\LdapException
     */
    protected function _setAttribute($name, $value, $append)
    {
        $this->assertChangeableAttribute($name);
        Attribute::setAttribute($this->currentData, $name, $value, $append);
    }

    /**
     * Sets a LDAP date/time attribute.
     *
     * This is an offline method.
     *
     * @param  string        $name
     * @param  integer|array $value
     * @param  boolean       $utc
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function setDateTimeAttribute($name, $value, $utc = false)
    {
        $this->_setDateTimeAttribute($name, $value, $utc, false);
        return $this;
    }

    /**
     * Appends to a LDAP date/time attribute.
     *
     * This is an offline method.
     *
     * @param  string        $name
     * @param  integer|array $value
     * @param  boolean       $utc
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function appendToDateTimeAttribute($name, $value, $utc = false)
    {
        $this->_setDateTimeAttribute($name, $value, $utc, true);
        return $this;
    }

    /**
     * Checks if the attribute can be set and sets it accordingly.
     *
     * @param  string        $name
     * @param  integer|array $value
     * @param  boolean       $utc
     * @param  boolean       $append
     * @throws Exception\LdapException
     */
    protected function _setDateTimeAttribute($name, $value, $utc, $append)
    {
        $this->assertChangeableAttribute($name);
        Attribute::setDateTimeAttribute($this->currentData, $name, $value, $utc, $append);
    }

    /**
     * Sets a LDAP password.
     *
     * @param  string $password
     * @param  string $hashType
     * @param  string $attribName
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function setPasswordAttribute($password, $hashType = Attribute::PASSWORD_HASH_MD5,
        $attribName = 'userPassword')
    {
        $this->assertChangeableAttribute($attribName);
        Attribute::setPassword($this->currentData, $password, $hashType, $attribName);
        return $this;
    }

    /**
     * Deletes a LDAP attribute.
     *
     * This method deletes the attribute.
     *
     * This is an offline method.
     *
     * @param  string $name
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function deleteAttribute($name)
    {
        if ($this->existsAttribute($name, true)) {
            $this->_setAttribute($name, null, false);
        }
        return $this;
    }

    /**
     * Removes duplicate values from a LDAP attribute
     *
     * @param  string $attribName
     * @return void
     */
    public function removeDuplicatesFromAttribute($attribName)
    {
        Attribute::removeDuplicatesFromAttribute($this->currentData, $attribName);
    }

    /**
     * Remove given values from a LDAP attribute
     *
     * @param  string      $attribName
     * @param  mixed|array $value
     * @return void
     */
    public function removeFromAttribute($attribName, $value)
    {
        Attribute::removeFromAttribute($this->currentData, $attribName, $value);
    }

    /**
     * @param  string $name
     * @return boolean
     * @throws Exception\LdapException
     */
    protected function assertChangeableAttribute($name)
    {
        $name = strtolower($name);
        $rdn = $this->getRdnArray(Dn::ATTR_CASEFOLD_LOWER);
        if ($name == 'dn') {
            throw new Exception\LdapException(null, 'DN cannot be changed.');
        }
        else if (array_key_exists($name, $rdn)) {
            throw new Exception\LdapException(null, 'Cannot change attribute because it\'s part of the RDN');
        } else if (in_array($name, self::$systemAttributes)) {
            throw new Exception\LdapException(null, 'Cannot change attribute because it\'s read-only');
        }
        else return true;
    }

    /**
     * Sets a LDAP attribute.
     *
     * This is an offline method.
     *
     * @param  string $name
     * @param  mixed  $value
     * @return null
     * @throws Exception\LdapException
     */
    public function __set($name, $value)
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Deletes a LDAP attribute.
     *
     * This method deletes the attribute.
     *
     * This is an offline method.
     *
     * @param  string $name
     * @return null
     * @throws Exception\LdapException
     */
    public function __unset($name)
    {
        $this->deleteAttribute($name);
    }

    /**
     * Sets a LDAP attribute.
     * Implements ArrayAccess.
     *
     * This is an offline method.
     *
     * @param  string $name
     * @param  mixed  $value
     * @return null
     * @throws Exception\LdapException
     */
    public function offsetSet($name, $value)
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Deletes a LDAP attribute.
     * Implements ArrayAccess.
     *
     * This method deletes the attribute.
     *
     * This is an offline method.
     *
     * @param  string $name
     * @return null
     * @throws Exception\LdapException
     */
    public function offsetUnset($name)
    {
        $this->deleteAttribute($name);
    }

    /**
     * Check if node exists on LDAP.
     *
     * This is an online method.
     *
     * @param  Ldap $ldap
     * @return boolean
     * @throws Exception\LdapException
     */
    public function exists(Ldap $ldap = null)
    {
        if ($ldap !== null) {
            $this->attachLdap($ldap);
        }
        $ldap = $this->getLdap();
        return $ldap->exists($this->_getDn());
    }

    /**
     * Reload node attributes from LDAP.
     *
     * This is an online method.
     *
     * @param  Ldap $ldap
     * @return Node Provides a fluid interface
     * @throws Exception\LdapException
     */
    public function reload(Ldap $ldap = null)
    {
        if ($ldap !== null) {
            $this->attachLdap($ldap);
        }
        $ldap = $this->getLdap();
        parent::reload($ldap);
        return $this;
    }

    /**
     * Search current subtree with given options.
     *
     * This is an online method.
     *
     * @param  string|Filter\AbstractFilter $filter
     * @param  integer                      $scope
     * @param  string                       $sort
     * @return Node\Collection
     * @throws Exception\LdapException
     */
    public function searchSubtree($filter, $scope = Ldap::SEARCH_SCOPE_SUB, $sort = null)
    {
        return $this->getLdap()->search($filter, $this->_getDn(), $scope, array('*', '+'), $sort,
            'Zend\Ldap\Node\Collection');
    }

    /**
     * Count items in current subtree found by given filter.
     *
     * This is an online method.
     *
     * @param  string|Filter\AbstractFilter $filter
     * @param  integer                      $scope
     * @return integer
     * @throws Exception\LdapException
     */
    public function countSubtree($filter, $scope = Ldap::SEARCH_SCOPE_SUB)
    {
        return $this->getLdap()->count($filter, $this->_getDn(), $scope);
    }

    /**
     * Count children of current node.
     *
     * This is an online method.
     *
     * @return integer
     * @throws Exception\LdapException
     */
    public function countChildren()
    {
        return $this->countSubtree('(objectClass=*)', Ldap::SEARCH_SCOPE_ONE);
    }

    /**
     * Gets children of current node.
     *
     * This is an online method.
     *
     * @param  string|Filter\AbstractFilter $filter
     * @param  string                       $sort
     * @return Node\Collection
     * @throws Exception\LdapException
     */
    public function searchChildren($filter, $sort = null)
    {
        return $this->searchSubtree($filter, Ldap::SEARCH_SCOPE_ONE, $sort);
    }

    /**
     * Checks if current node has children.
     * Returns whether the current element has children.
     *
     * Can be used offline but returns false if children have not been retrieved yet.
     *
     * @return boolean
     * @throws Exception\LdapException
     */
    public function hasChildren()
    {
        if (!is_array($this->children)) {
            if ($this->isAttached()) {
                return ($this->countChildren() > 0);
            } else {
                return false;
            }
        } else {
            return (count($this->children) > 0);
        }
    }

    /**
     * Returns the children for the current node.
     *
     * Can be used offline but returns an empty array if children have not been retrieved yet.
     *
     * @return Node\ChildrenIterator
     * @throws Exception\LdapException
     */
    public function getChildren()
    {
        if (!is_array($this->children)) {
            $this->children = array();
            if ($this->isAttached()) {
                $children = $this->searchChildren('(objectClass=*)', null);
                foreach ($children as $child) {
                    $this->children[$child->getRdnString(Dn::ATTR_CASEFOLD_LOWER)] = $child;
                }
            }
        }
        return new Node\ChildrenIterator($this->children);
    }

    /**
     * Returns the parent of the current node.
     *
     * @param  Ldap $ldap
     * @return Node
     * @throws Exception\LdapException
     */
    public function getParent(Ldap $ldap = null)
    {
        if ($ldap !== null) {
            $this->attachLdap($ldap);
        }
        $ldap = $this->getLdap();
        $parentDn = $this->_getDn()->getParentDn(1);
        return self::fromLdap($parentDn, $ldap);
    }

    /**
     * Return the current attribute.
     * Implements Iterator
     *
     * @return array
     */
    public function current()
    {
        return $this;
    }

    /**
     * Return the attribute name.
     * Implements Iterator
     *
     * @return string
     */
    public function key()
    {
        return $this->getRdnString();
    }

    /**
     * Move forward to next attribute.
     * Implements Iterator
     */
    public function next()
    {
        $this->iteratorRewind = false;
    }

    /**
     * Rewind the Iterator to the first attribute.
     * Implements Iterator
     */
    public function rewind()
    {
        $this->iteratorRewind = true;
    }

    /**
     * Check if there is a current attribute
     * after calls to rewind() or next().
     * Implements Iterator
     *
     * @return boolean
     */
    public function valid()
    {
        return $this->iteratorRewind;
    }
}
