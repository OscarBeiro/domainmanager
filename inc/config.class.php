<?php

/**
 */

use Glpi\Application\View\TemplateRenderer;

// phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace
class Plugin0GLPIXxConfig extends CommonDBTM
{
    public static $rightname = 'config';

    private static ?self $instance = null;

    /**
     * {@inheritDoc}
     */
    public function __construct()
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($DB->tableExists($this->getTable())) {
            $this->getFromDB(1);
        }
    }

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0): string
    {
        return '0GLPIXO';
    }

    /**
     * getInstance
     *
     * @param  int $n
     * @return Plugin0GLPIXxConfig
     */
    public static function getInstance(int $n = 1): Plugin0GLPIXxConfig
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            if (!self::$instance->getFromDB($n)) {
                self::$instance->getEmpty();
            }
        }

        return self::$instance;
    }

    /**
     * {@inheritDoc}
     */
    public function prepareInputForUpdate($input): false|array
    {
        // Handle password fields

        // Log update fields in history manually
        foreach ($this->fields as $key => $value) {
            if (isset($input[$key]) && $input[$key] != $value) {
                Log::history('1', Config::class, [1, $key . ' ' . $value, $input[$key]]);
            }
        }

        return $input;
    }

    /**
     * @param  array $input
     * @param  string $key
     *
     * @return array
     */
    private static function blankPassword(array $input, string $key): array
    {
        if (isset($input[$key])) {
            if (!empty($input[$key])) {
                $input[$key] = (new GLPIKey())->encrypt($input[$key]);
            } else {
                unset($input[$key]);
            }
        }
        if (isset($input["_blank_{$key}"]) && $input["_blank_{$key}"]) {
            $input[$key] = '';
        }

        return $input;
    }

    /**
     * {@inheritDoc}
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        switch ($item::getType()) {
            case Config::getType():
                return self::createTabEntry(self::getTypeName(1));
        }

        return '';
    }

    /**
     * {@inheritDoc}
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        switch ($item::getType()) {
            case Config::getType():
                return self::showFormConfig();
        }

        return false;
    }

    /**
     * Display the configuration form
     */
    public static function showFormConfig(): bool
    {
        $config = self::getInstance();
        $template = "@0GLPIxx/pages/config.html.twig";
        TemplateRenderer::getInstance()->display($template, [
            'item' => $config,
        ]);

        return true;
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
            /*
            $DB->doQuery($query);

            $config = new self();
            $config->add([
                'id' => 1,
            ]);
            */

            // * Remove this line when the plugin is ready
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Plugin 0GLPIXO: %s not configured', '0GLPIxx'),
                    $table,
                ),
                false,
                WARNING,
            );
        } else {
            // Migrations
            // $migration->migrationOneTable($table);
        }
    }
}
