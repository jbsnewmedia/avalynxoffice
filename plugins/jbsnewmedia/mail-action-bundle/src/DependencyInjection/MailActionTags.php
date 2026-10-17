<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle\DependencyInjection;

final class MailActionTags
{
    public const string COMPOSE_BUTTON = 'app.mail_action.compose_button';

    public const string EMAIL_ACTION = 'app.mail_action.email_action';

    public const string CONTACT_ACTION = 'app.mail_action.contact_action';

    public const string CALENDAR_ACTION = 'app.mail_action.calendar_action';
}
