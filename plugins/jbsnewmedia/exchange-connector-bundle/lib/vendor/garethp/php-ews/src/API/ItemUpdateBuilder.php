<?php

namespace garethp\ews\API;

class ItemUpdateBuilder
{
    /**
     * @param string $itemType
     * @param string $uriType
     * @param array $changes
     * @return array
     */
    public static function buildUpdateItemChanges($itemType, $uriType, $changes)
    {
        $updateArray = [];

        if (isset($changes['deleteFields'])) {
            $updateArray['DeleteItemField'] = self::buildUpdateDeleteItemFields($uriType, $changes['deleteFields']);
            unset($changes['deleteFields']);
        }

        $updateArray['SetItemField'] = self::buildUpdateSetItemFields($itemType, $uriType, $changes);

        return $updateArray;
    }

    protected static function buildUpdateDeleteItemFields($uriType, $changes)
    {
        $deleteItemFields = [];
        foreach ($changes as $key) {
            if (strpos($key, 'PhysicalAddress:') === 0 && $uriType == "contacts") {
                $deleteItemFields =
                    self::deleteContactPhysicalAddressField($key, $deleteItemFields);
            } else {
                list($fieldUriType, $fieldKey) = self::getFieldURI($uriType, $key);
                $deleteItemFields[] = [$fieldUriType => $fieldKey];
            }
        }

        return $deleteItemFields;
    }

    protected static function buildUpdateSetItemFields($itemType, $uriType, $changes)
    {
        $setItemFields = [];
        foreach ($changes as $key => $valueArray) {
            $valueArray = self::splitDictionaryUpdateEntries($valueArray);
            if (!is_array($valueArray) || Type::arrayIsAssoc($valueArray)) {
                $valueArray = array($valueArray);
            }

            foreach ($valueArray as $value) {
                list ($fieldUriType, $fieldKey, $valueKey, $value) = self::getFieldURI($uriType, $key, $value);
                $setItemFields[] = array(
                    $fieldUriType => $fieldKey,
                    $itemType => [$valueKey => $value]
                );
            }
        }

        return $setItemFields;
    }

    protected static function deleteContactPhysicalAddressField($key, $deleteItemFields)
    {
        $key = explode(":", $key);
        $dictionaryFields = FieldURIManager::getDictionaryURIFields();
        if (count($key) == 2 && !isset($dictionaryFields['physicaladdress']['contacts'][strtolower($key[1])])) {
            foreach ($dictionaryFields['physicaladdress']['contacts'] as $uriKey => $uri) {
                $deleteItemFields[] = ['IndexedFieldURI' => ['FieldURI' => $uri, 'FieldIndex' => $key[1]]];
            }
        } elseif (count($key) == 3 && isset($dictionaryFields['physicaladdress']['contacts'][strtolower($key[1])])) {
            $uri = $dictionaryFields['physicaladdress']['contacts'][strtolower($key[1])];
            $deleteItemFields[] = ['IndexedFieldURI' => ['FieldURI' => $uri, 'FieldIndex' => $key[2]]];
        }

        return $deleteItemFields;
    }

    protected static function splitDictionaryUpdateEntries($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        reset($value);
        $fieldKey = key($value);

        if (!is_array($value[$fieldKey]) || empty($value[$fieldKey]['Entry'])) {
            return $value;
        }

        $entryKey = $value[$fieldKey]['Entry']['Key'];
        unset($value[$fieldKey]['Entry']['Key']);

        $newValue = [];
        foreach ($value[$fieldKey]['Entry'] as $key => $updateValue) {
            $newValue[] = [$fieldKey => ['Entry' => ['Key' => $entryKey, $key => $updateValue]]];
        }

        $value = $newValue;

        return $value;
    }

    protected static function getFieldURI($uriType, $key = null, $value = null)
    {
        if (strpos($key, ':') !== false) {
            try {
                $fieldUriValue = substr($key, 0, strpos($key, ':'));

                list ($key, $index) = explode(':', $key);

                if (is_array($value)) {
                    $key = key($value);
                    $value = $value[$key];
                }

                if (is_array($value) && !empty($value['Entry']) && is_array($value['Entry'])) {
                    $entryKey = $value['Entry']['Key'];
                    unset($value['Entry']['Key']);
                    reset($value['Entry']);

                    $fieldKey = key($value['Entry']);
                    $value['Entry']['Key'] = $entryKey;
                    $fieldUri = FieldURIManager::getIndexedFieldUriByName($fieldUriValue, $uriType, $fieldKey);
                } else {
                    $fieldUri = FieldURIManager::getIndexedFieldUriByName($fieldUriValue, $uriType);
                }

                return ['IndexedFieldURI', ['FieldURI' => $fieldUri, 'FieldIndex' => $index], $key, $value];
            } catch (\Exception $e) {
            }
        }

        $fullName = FieldURIManager::getFieldUriByName($key, $uriType);

        return ['FieldURI', ['FieldURI' => $fullName], $key, $value];
    }
}
