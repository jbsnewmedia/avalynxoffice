<?php

namespace garethp\ews;

use garethp\ews\API\Enumeration\DistinguishedFolderIdNameType;
use garethp\ews\API\Type;
use garethp\ews\API\Type\MessageType;

class MailAPI extends API
{
    /**
     * @var Type\BaseFolderIdType
     */
    protected $folderId;

    /**
     * @return Type\BaseFolderIdType
     */
    public function getFolderId()
    {
        if (!$this->folderId) {
            $this->folderId = $this->getDistinguishedFolderId('inbox');
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
     * @param string $displayName
     * @param string|Type\BaseFolderIdType $parentFolder
     */
    public function pickMailFolder($displayName = null, $parentFolder = 'inbox')
    {
        if ($displayName === null) {
            $this->folderId = $this->getDistinguishedFolderId('inbox');
            return;
        }

        $folder = $this->getFolderByDisplayName($displayName, $parentFolder);
        $this->folderId = $folder->getFolderId();
    }

    protected function formatRestrictions($restrictions)
    {
        return RestrictionFormatter::format('message', $restrictions);
    }

    /**
     * Get all mail items in the inbox
     *
     * @param Type\BaseFolderIdType $folderId
     * @param array $options
     * @return Type\MessageType[]
     */
    public function getMailItems($folderId = null, $options = array())
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

        if (!empty($options['Restriction'])) {
            $options['Restriction'] = $this->formatRestrictions($options['Restriction']);
        }

        $request = array_replace_recursive($request, $options);

        $request = Type::buildFromArray($request);
        return $this->getClient()->FindItem($request);
    }

    /**
     * @param Type\BaseFolderIdType $folderId
     * @param array $options
     * @return Type\MessageType[]
     */
    public function getUnreadMailItems($folderId = null, $options = array())
    {
        $unReadOption = array(
            'Restriction' => array(
                'IsEqualTo' => array(
                    'IsRead' => false
                )
            )
        );

        $options = array_replace_recursive($unReadOption, $options);

        return $this->getMailItems($folderId, $options);
    }

    /**
     * Updates a calendar item with changes
     *
     * @param $itemId Type\ItemIdType|Type
     * @param $changes
     * @param array $options
     * @return Type\MessageType[]
     */
    public function updateMailItem($itemId, $changes, $options = [])
    {
        //Create the request
        $request = array(
            'ItemChange' => array(
                'ItemId' => $itemId->toArray(),
                'Updates' => API\ItemUpdateBuilder::buildUpdateItemChanges('Message', 'message', $changes)
            )
        );

        $items = $this->updateItems($request, $options);

        if (!is_array($items)) {
            $items = array($items);
        }

        return $items;
    }

    /**
     * @param $mailItem Type\MessageType|Type\ItemIdType
     * @param $isRead boolean
     */
    public function markMailAsRead($mailItem, $isRead = true)
    {
        if ($mailItem instanceof Type\MessageType) {
            $mailItem = $mailItem->getItemId();
        }

        $this->updateMailItem($mailItem, array(
            'IsRead' => $isRead
        ));
    }

    public function sendMail(MessageType $message, $options = array())
    {
        $items = array('Message' => $message->toXmlObject());
        $defaultOptions = array(
            'MessageDisposition' => 'SendAndSaveCopy',
        );

        if ($this->getPrimarySmtpMailbox() != null) {
            $sentItems = $this->getDistinguishedFolderId('sentitems');
            $defaultOptions['SavedItemFolderId'] = $sentItems->toArray(true);
        }

        $options = array_replace_recursive($defaultOptions, $options);

        return $this->createItems($items, $options);
    }

    public function getAttachment(Type\AttachmentIdType $attachmentId, $options = [])
    {
        $request = array(
            'AttachmentIds' => array(
                $attachmentId->toXmlObject()
            ),
            'AttachmentShape' => array(
                'IncludeMimeContent' => true
            )
        );

        $request = array_replace_recursive($request, $options);

        return $this->getClient()->GetAttachment($request);
    }

    /**
     * @param array $options
     * @return API\Message\EmptyFolderResponseType
     */
    public function emptyTrash(array $options = [])
    {
        return $this->emptyFolder(
            $this->getDistinguishedFolderId(DistinguishedFolderIdNameType::DELETEDITEMS),
            'SoftDelete',
            false,
            $options
        );
    }
}
