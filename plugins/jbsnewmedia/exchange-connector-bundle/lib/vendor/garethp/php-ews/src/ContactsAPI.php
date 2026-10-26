<?php

namespace garethp\ews;

use garethp\ews\API\Exception;
use garethp\ews\API\Type;

class ContactsAPI extends API
{
    /**
     * @var Type\BaseFolderIdType
     */
    protected $folderId;

    /**
     * Pick a Contacts based on it's folder name
     *
     * @param string|null $displayName
     * @return $this
     * @throws Exception
     */
    public function pickContactsFolder($displayName = null)
    {
        if ($displayName === 'default.contacts' || $displayName === null) {
            $this->folderId = $this->getFolderByDistinguishedId('contacts')->getFolderId();
        } else {
            $this->folderId = $this->getFolderByDisplayName($displayName, 'contacts')->getFolderId();
        }

        if ($this->folderId === null) {
            throw new Exception('Folder does not exist');
        }

        return $this;
    }

    /**
     * @return Type\BaseFolderIdType
     */
    public function getFolderId()
    {
        if (!$this->folderId) {
            $this->pickContactsFolder();
            //            $this->folderId = $this->getFolderByDistinguishedId('contacts')->getFolderId();
        }

        return $this->folderId;
    }

    /**
     * @param Type\BaseFolderIdType $folderId
     */
    public function setFolderId($folderId)
    {
        $this->folderId = $folderId;
    }

    /**
     * @param Type\BaseFolderIdType $folderId
     * @param array $options
     * @return Type\ContactItemType[]
     */
    public function getContacts($folderId = null, $options = array())
    {
        if (!$folderId) {
            $folderId = $this->getFolderId();
        }

        $request = array(
            'Traversal' => 'Shallow',
            'ItemShape' => array(
                'BaseShape' => 'AllProperties'
            ),
            'ParentFolderIds' => $folderId->toArray(true)
        );

        $request = array_replace_recursive($request, $options);

        $request = Type::buildFromArray($request);

        return $this->getClient()->FindItem($request);
    }

    /**
     * @param Type\ItemIdType $itemId
     * @param array $options
     * @return Type\ContactItemType
     */
    public function getContact($itemId, $options = array())
    {
        return $this->getItem($itemId, $options);
    }

    /**
     * @param $contacts
     * @param array $options
     * @return Type\ItemIdType[]
     */
    public function createContacts($contacts, $options = array())
    {
        $request = array('Contact' => $contacts);

        $defaultOptions = array(
            'MessageDisposition' => 'SaveOnly',
            'SavedItemFolderId' => $this->getFolderId()->toArray(true)
        );
        $options = array_replace_recursive($defaultOptions, $options);

        $result = $this->createItems($request, $options);

        if (!is_array($result)) {
            $result = array($result);
        }

        return $result;
    }

    public function updateContactItem(Type\ItemIdType $itemId, $changes, $options = [])
    {
        //Create the request
        $request = array(
            'ItemChange' => array(
                'ItemId' => $itemId->toArray(),
                'Updates' => API\ItemUpdateBuilder::buildUpdateItemChanges('Contact', 'contacts', $changes)
            )
        );

        $items = $this->updateItems($request, $options);

        if (!is_array($items)) {
            $items = array($items);
        }

        return $items;
    }
}
