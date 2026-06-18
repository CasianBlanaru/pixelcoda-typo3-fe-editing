<?php

declare(strict_types=1);

namespace PixelCoda\FeEditor\Utility;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class PermissionChecker
{
    public static function mayEditFrontend(?BackendUserAuthentication $be): bool
    {
        if (!$be instanceof BackendUserAuthentication) {
            return false;
        }
        $userTsConfig = $be->getTSConfig();
        $extensionConfig = is_array($userTsConfig['tx_pixelcodafeeditor.'] ?? null)
            ? $userTsConfig['tx_pixelcodafeeditor.']
            : [];
        if ((bool) ($extensionConfig['disabled'] ?? false)) {
            return false;
        }
        if ($be->isAdmin()) {
            return true;
        }
        return $be->check('tables_modify', 'tt_content');
    }
}
