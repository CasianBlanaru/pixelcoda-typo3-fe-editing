<?php

namespace PixelCoda\FeEditor\Security;

use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;

final class TokenService
{
    public function __construct(
        private readonly FormProtectionFactory $formProtectionFactory
    ) {}

    public function beToken(string $context): string
    {
        return $this->formProtectionFactory->createForType('backend')->generateToken($context);
    }
}
