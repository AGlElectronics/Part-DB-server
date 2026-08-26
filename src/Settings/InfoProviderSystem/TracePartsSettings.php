<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2026 Part-DB contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace App\Settings\InfoProviderSystem;

use App\Form\Type\APIKeyType;
use App\Settings\SettingsIcon;
use Jbtronics\SettingsBundle\Metadata\EnvVarMode;
use Jbtronics\SettingsBundle\Settings\Settings;
use Jbtronics\SettingsBundle\Settings\SettingsParameter;
use Jbtronics\SettingsBundle\Settings\SettingsTrait;
use Symfony\Component\Form\Extension\Core\Type\LanguageType;
use Symfony\Component\Translation\TranslatableMessage as TM;
use Symfony\Component\Validator\Constraints as Assert;

#[Settings(label: new TM('settings.ips.traceparts'), description: new TM('settings.ips.traceparts.help'))]
#[SettingsIcon('fa-cube')]
class TracePartsSettings
{
    use SettingsTrait;

    #[SettingsParameter(
        label: new TM('settings.ips.traceparts.api_key'),
        formType: APIKeyType::class,
        envVar: 'PROVIDER_TRACEPARTS_API_KEY',
        envVarMode: EnvVarMode::OVERWRITE
    )]
    public ?string $apiKey = null;

    #[SettingsParameter(
        label: new TM('settings.ips.traceparts.tenant_uid'),
        formType: APIKeyType::class,
        envVar: 'PROVIDER_TRACEPARTS_TENANT_UID',
        envVarMode: EnvVarMode::OVERWRITE
    )]
    #[Assert\Uuid]
    public ?string $tenantUid = null;

    #[SettingsParameter(
        label: new TM('settings.ips.traceparts.catalog'),
        description: new TM('settings.ips.traceparts.catalog.help'),
        envVar: 'PROVIDER_TRACEPARTS_CATALOG',
        envVarMode: EnvVarMode::OVERWRITE
    )]
    public ?string $catalog = null;

    #[SettingsParameter(
        label: new TM('settings.ips.traceparts.language'),
        formType: LanguageType::class,
        formOptions: ['preferred_choices' => ['en', 'nl', 'de', 'fr']],
        envVar: 'PROVIDER_TRACEPARTS_LANGUAGE',
        envVarMode: EnvVarMode::OVERWRITE
    )]
    #[Assert\Language]
    public string $language = 'en';

    #[SettingsParameter(
        label: new TM('settings.ips.traceparts.approved'),
        description: new TM('settings.ips.traceparts.approved.help'),
        envVar: 'PROVIDER_TRACEPARTS_SYNDICATION_APPROVED',
        envVarMode: EnvVarMode::OVERWRITE
    )]
    public bool $syndicationApproved = false;
}
