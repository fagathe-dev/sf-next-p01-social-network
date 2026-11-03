<?php

namespace Fagathe\GatherCore\Enum\Auth;

enum UserRequestTypeEnum: string
{
    case OtpRegister = 'otp_register';
    case OtpLogin = 'otp_login';
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    case EmailChange = 'email_change';
    case AccountDeletion = 'account_deletion';
    case AdminHandoff = 'admin_handoff';
}
