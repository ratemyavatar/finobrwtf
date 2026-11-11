# admin controls

the server list has 108 controls. anything not on this list can't be run through the api.

## moderation

| control | id |
| --- | --- |
| ban for 1 day | `moderation-ban-for-1-day` |
| ban for 3 days | `moderation-ban-for-3-days` |
| ban for 7 days | `moderation-ban-for-7-days` |
| ban for 14 days | `moderation-ban-for-14-days` |
| ban for 30 days | `moderation-ban-for-30-days` |
| issue a warning | `moderation-issue-a-warning` |
| send a reminder | `moderation-send-a-reminder` |
| terminate account | `moderation-terminate-account` |
| lift active bans | `moderation-lift-active-bans` |
| dismiss active warnings | `moderation-dismiss-active-warnings` |
| add a private case note | `moderation-add-a-private-case-note` |
| read moderation history | `moderation-read-moderation-history` |
| view private case notes | `moderation-view-private-case-notes` |
| revoke login sessions | `moderation-revoke-login-sessions` |
| active bans | `moderation-active-bans` |
| warnings awaiting acknowledgement | `moderation-warnings-awaiting-acknowledgement` |

## access restrictions

| control | id |
| --- | --- |
| block forum posting | `access-restrictions-block-forum-posting` |
| restore forum posting | `access-restrictions-restore-forum-posting` |
| block private messages | `access-restrictions-block-private-messages` |
| restore private messages | `access-restrictions-restore-private-messages` |
| block asset uploads | `access-restrictions-block-asset-uploads` |
| restore asset uploads | `access-restrictions-restore-asset-uploads` |
| block trading | `access-restrictions-block-trading` |
| restore trading | `access-restrictions-restore-trading` |
| block avatar changes | `access-restrictions-block-avatar-changes` |
| restore avatar changes | `access-restrictions-restore-avatar-changes` |

## accounts

| control | id |
| --- | --- |
| edit username | `accounts-edit-username` |
| edit email | `accounts-edit-email` |
| edit profile blurb | `accounts-edit-profile-blurb` |
| set place slots | `accounts-set-place-slots` |
| require email verification | `accounts-require-email-verification` |
| pause daily dius | `accounts-pause-daily-dius` |
| resume daily dius | `accounts-resume-daily-dius` |
| use default profile picture | `accounts-use-default-profile-picture` |
| newest accounts | `accounts-newest-accounts` |
| inspect account | `accounts-inspect-account` |
| read account changes | `accounts-read-account-changes` |

## economy

| control | id |
| --- | --- |
| credit dius | `economy-credit-dius` |
| debit dius | `economy-debit-dius` |
| recent transactions | `economy-recent-transactions` |
| inspect transaction | `economy-inspect-transaction` |
| grant inventory item | `economy-grant-inventory-item` |
| revoke inventory item | `economy-revoke-inventory-item` |

## catalog

| control | id |
| --- | --- |
| asset: inspect | `catalog-inspect` |
| asset: edit title | `catalog-edit-title` |
| asset: edit description | `catalog-edit-description` |
| asset: send to review | `catalog-send-to-review` |
| asset: view review queue | `catalog-view-review-queue` |
| asset: view published items | `catalog-view-published-items` |
| asset: view denied items | `catalog-view-denied-items` |
| asset: take off sale | `catalog-take-off-sale` |
| asset: put on sale | `catalog-put-on-sale` |
| asset: set price | `catalog-set-price` |
| approve asset | `catalog-approve-asset` |
| deny asset | `catalog-deny-asset` |

## places

| control | id |
| --- | --- |
| feature place | `places-feature-place` |
| unfeature place | `places-unfeature-place` |
| set place client version | `places-set-place-client-version` |
| set place capacity | `places-set-place-capacity` |
| all places | `places-all-places` |

## avatar

| control | id |
| --- | --- |
| inspect avatar data | `avatar-inspect-avatar-data` |
| unequip hats | `avatar-unequip-hats` |
| unequip gear | `avatar-unequip-gear` |
| unequip shirt | `avatar-unequip-shirt` |
| unequip pants | `avatar-unequip-pants` |
| unequip t-shirt | `avatar-unequip-t-shirt` |
| unequip face | `avatar-unequip-face` |
| reset avatar to classic noob | `avatar-reset-avatar-to-classic-noob` |

## messages

| control | id |
| --- | --- |
| send staff message | `messages-send-staff-message` |

## forum

| control | id |
| --- | --- |
| thread: inspect | `forum-thread-inspect` |
| thread: replace body with moderation notice | `forum-thread-replace-body-with-moderation-notice` |
| reply: inspect | `forum-reply-inspect` |
| reply: replace body with moderation notice | `forum-reply-replace-body-with-moderation-notice` |
| lock thread | `forum-lock-thread` |
| unlock thread | `forum-unlock-thread` |
| pin thread | `forum-pin-thread` |
| unpin thread | `forum-unpin-thread` |
| move thread to category | `forum-move-thread-to-category` |

## invites

| control | id |
| --- | --- |
| create an invite | `invites-create-an-invite` |
| all invite keys | `invites-all-invite-keys` |
| revoke unused invite | `invites-revoke-unused-invite` |

## announcements

| control | id |
| --- | --- |
| create announcement | `announcements-create-announcement` |
| recent announcements | `announcements-recent-announcements` |
| edit announcement text | `announcements-edit-announcement-text` |
| set announcement expiry | `announcements-set-announcement-expiry` |
| expire announcement now | `announcements-expire-announcement-now` |

## site settings

| control | id |
| --- | --- |
| pause forum posting | `site-settings-pause-forum-posting` |
| resume forum posting | `site-settings-resume-forum-posting` |
| pause asset uploads | `site-settings-pause-asset-uploads` |
| resume asset uploads | `site-settings-resume-asset-uploads` |
| pause trading | `site-settings-pause-trading` |
| resume trading | `site-settings-resume-trading` |
| pause avatar changes | `site-settings-pause-avatar-changes` |
| resume avatar changes | `site-settings-resume-avatar-changes` |
| pause registration | `site-settings-pause-registration` |
| resume registration | `site-settings-resume-registration` |
| pause new logins | `site-settings-pause-new-logins` |
| resume new logins | `site-settings-resume-new-logins` |

## staff

| control | id |
| --- | --- |
| grant staff access | `staff-grant-access` |
| revoke staff access | `staff-revoke-access` |
| list delegated staff | `staff-list-delegated-staff` |
| revoke all delegated access | `staff-revoke-all-delegated-access` |
| show staff permissions | `staff-show-staff-permissions` |

## audit

| control | id |
| --- | --- |
| recent admin actions | `audit-recent-admin-actions` |
| actions on this account | `audit-actions-on-this-account` |

## operations

| control | id |
| --- | --- |
| check database connection | `operations-check-database-connection` |
| inspect pending jobs | `operations-inspect-pending-jobs` |
| inspect failed jobs | `operations-inspect-failed-jobs` |
