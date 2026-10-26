<?php

namespace garethp\ews;

class RestrictionFormatter
{
    public static function format($preference, $restrictions)
    {
        foreach ($restrictions as $restrictionType => $query) {
            if (!in_array($restrictionType, ['And', 'Not', 'Or'])) {
                $restrictions[$restrictionType] = self::formatExpression($preference, $query);
            } else {
                $restrictions[$restrictionType] = self::format($preference, $query);
            }
        }

        return $restrictions;
    }

    private static function formatExpression($preference, $expression)
    {
        if (count($expression) > 1 && is_array(current($expression))) {
            return array_map(function ($subExpression) use ($preference) {
                return self::formatExpression($preference, $subExpression)[0];
            }, $expression);
        }

        return self::formatFieldURI($preference, $expression);
    }

    private static function formatFieldURI($preference, $expression)
    {
        $formattedRestrictionType = [];

        foreach ($expression as $key => $value) {
            if ($value === false) {
                $value = 'false';
            }
            $formattedRestrictionType[] = array(
                'FieldURI' => array('FieldURI' => API\FieldURIManager::getFieldUriByName($key, $preference)),
                'FieldURIOrConstant' => array('Constant' => array('Value' => (string)$value))
            );
        }

        return $formattedRestrictionType;
    }
}
