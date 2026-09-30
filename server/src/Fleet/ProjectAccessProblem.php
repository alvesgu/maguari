<?php

declare(strict_types=1);

namespace Maguari\Server\Fleet;

/**
 * Why Maguari cannot list instances in a project. Each case has one fixed
 * sentence for the administrator; raw Google Cloud messages are never shown.
 */
enum ProjectAccessProblem
{
    case CredentialsUnavailable;
    case NotFound;
    case NotFoundOrNoPermission;
    case ApiDisabled;
    case ScopeInsufficient;
    case UnexpectedResponse;

    public function message(): string
    {
        return match ($this) {
            self::CredentialsUnavailable => 'Maguari could not obtain Google Cloud credentials.',
            self::NotFound => 'Project not found.',
            self::NotFoundOrNoPermission => 'Project not found, or no permission to access it. '
                . 'Check the project ID and grant Maguari\'s service account the custom role in this project.',
            self::ApiDisabled => 'The Compute Engine API is not enabled in this project.',
            self::ScopeInsufficient => 'The server instance\'s access scopes do not allow Compute Engine API calls. '
                . 'Give the instance the cloud-platform scope.',
            self::UnexpectedResponse => 'Google Cloud returned an unexpected response. Try again.',
        };
    }
}
