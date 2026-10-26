<?php

namespace garethp\ews\Utilities;

use garethp\ews\API\Type\BaseFolderIdType;

function getFolderIds($folderIds)
{
    $folders = ensureIsArray($folderIds);

    $folderIds = array_map(function (BaseFolderIdType $folderId) {
        return $folderId->toArray(true);
    }, $folders);

    return array_reduce($folderIds, function ($folderIds, $folderId) {
        $folderIds[key($folderId)][] = current($folderId);
        return $folderIds;
    }, []);
}
