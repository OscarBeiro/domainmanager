<?php

/**
 */

// phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace
class Plugin0GLPIXxDropdown extends CommonDropdown
{
    public static $rightname = "dropdown";

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0): string
    {
        return _n('0GLPIXO', '0GLPIXOs', $nb, '0GLPIxx');
    }

    /**
     * {@inheritDoc}
     */
    public function getAdditionalFields(): array
    {
        return [
            [
                'name'  => 'fieldname',
                'label' => __('Name'),
                'type'  => 'text',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'        => 'n', // n over 2, name, comment as default fields
            'table'     => self::getTable(),
            'field'     => 'fieldname',
            'name'      => __('Name'),
            'datatype'  => 'text',
        ];

        return $tab;
    }

    /**
     * install
     *
     * @param  Migration $migration
     * @return void
     */
    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $default_charset    = DBConnection::getDefaultCharset();
        $default_collation  = DBConnection::getDefaultCollation();
        $default_key_sign   = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = "CREATE TABLE IF NOT EXISTS `$table` (
                `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id`)
            )ENGINE=InnoDB DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
            // $DB->doQuery($query);

            // * Remove this line when the plugin is ready
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Plugin 0GLPIXO: %s not configured', '0GLPIxx'),
                    $table,
                ),
                false,
                WARNING,
            );
        }
    }
}
