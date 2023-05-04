<?php
/**
 * copied from XF301VB official addon
 */
namespace ForumSystems\WoltLab\Option;
use XF;
use XF\Entity\Option;
use XF\Install\Data\MySql;
use XF\Option\AbstractOption;

class ImportTable extends AbstractOption
{

    public static function renderOption(Option $option, array $htmlParams): string
    {
        $choices = ['' => XF::phrase('none')] + self::getTableChoices();

        return self::getSelectRow($option, $htmlParams, $choices, $option->option_value);
    }

    public static function verifyOption($value, Option $option): bool
    {
        if ($value === '')
        {
            return true;
        }

        if (!in_array($value, self::getTableChoices(), true))
        {
            $option->error(XF::phrase('specified_table_does_not_exist'), $option->option_id);
            return false;
        }

        $columns = XF::app()->db()->fetchAllColumn('SHOW COLUMNS FROM ' . $value);
        foreach (['content_type', 'old_id', 'new_id'] AS $column)
        {
            if (!in_array($column, $columns, true))
            {
                $option->error(XF::phrase('specified_table_does_not_appear_to_be_import_log'), $option->option_id);
                return false;
            }
        }

        return true;
    }

    protected static function getTableChoices(): array
    {

        $schemaTables = (new MySql())->getTables();
        $choices = [];
        foreach (XF::app()->db()->fetchAllColumn('SHOW TABLES') AS $table)
        {
            if (!isset($schemaTables[$table]) && strpos($table, 'xf_rm_') !== 0 && strpos($table, 'xf_mg_') !== 0)
            {
                $choices[$table] = $table;
            }
        }

        return $choices;
    }
}