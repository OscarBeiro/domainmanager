<?php

/**
 */

// phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace
class Plugin0GLPIXxProfile extends Profile
{
    public static $rightname = "profile";

    /**
     * {@inheritDoc}
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        switch ($item::getType()) {
            case Profile::getType():
                return self::createTabEntry(self::getTypeName(1));
        }

        return '';
    }

    /**
     * getStandardCRUD
     *
     * @return array
     */
    private function getStandardCRUD(): array
    {
        return [
            READ    => __('Read'),
            UPDATE  => __('Update'),
            CREATE  => __('Create'),
            DELETE  => __('Delete'),
            PURGE   => __('Purge'),
        ];
    }

    /**
     * getAllRights
     *
     * @return array
     */
    public function getAllRights(): array
    {
        return [
            [
                'rights'    => self::getStandardCRUD(),
                'label'     => __('0GLPIXO', '0GLPIxx'),
                'itemtype'  => Plugin0GLPIXxConfig::class,
                'field'     => 'plugin_0GLPIxx_config',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() == Profile::getType()) {
            /** @var Profile $item */
            return self::displayProfileForm($item);
        }

        return false;
    }

    /**
     * displayProfileForm
     *
     * @param  Profile $profile
     * @return bool
     */
    public static function displayProfileForm(Profile $profile): bool
    {
        $can_edit = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='spaced'>";
        if ($can_edit) {
            echo "<form method='post' action='" . htmlspecialchars($profile::getFormURL()) . "'>";
        }

        $matrix_options = [
            'canedit' => $can_edit,
            'title'   => '0GLPIXO',
        ];
        $rights = (new self())->getAllRights();
        $profile->displayRightsChoiceMatrix($rights, $matrix_options);

        if ($can_edit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $profile->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>\n";
            Html::closeForm();
        }
        echo '</div>';

        return true;
    }

    /**
     * uninstall
     *
     * @param  Migration $migration
     * @return void
     */
    public static function uninstall(Migration $migration)
    {
        $migration->displayMessage("Removing profile rights");
        $profile = new self();
        foreach ($profile->getAllRights() as $data) {
            ProfileRight::deleteProfileRights([$data['field']]);
        }
    }
}
