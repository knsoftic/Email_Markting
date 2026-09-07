<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * The permission catalogue staff roles are built from. Super admins and
 * account owners bypass this list entirely (see User::hasPermission).
 */
class PermissionSeeder extends Seeder
{
    /** group => [slug => label] */
    public const CATALOGUE = [
        'contacts' => [
            'contacts.view' => 'View contacts',
            'contacts.create' => 'Create contacts',
            'contacts.update' => 'Edit contacts',
            'contacts.delete' => 'Delete contacts',
            'contacts.import' => 'Import contacts',
            'contacts.export' => 'Export contacts',
        ],
        'campaigns' => [
            'campaigns.view' => 'View campaigns',
            'campaigns.create' => 'Create campaigns',
            'campaigns.update' => 'Edit campaigns',
            'campaigns.delete' => 'Delete campaigns',
            'campaigns.send' => 'Send and schedule campaigns',
        ],
        'templates' => [
            'templates.view' => 'View templates',
            'templates.create' => 'Create templates',
            'templates.update' => 'Edit templates',
            'templates.delete' => 'Delete templates',
        ],
        'inbox' => [
            'inbox.view' => 'Read inbox',
            'inbox.send' => 'Send and reply to email',
            'inbox.delete' => 'Delete email',
        ],
        'mailboxes' => [
            'mailboxes.view' => 'View mailboxes',
            'mailboxes.manage' => 'Connect and manage mailboxes',
        ],
        'smtp' => [
            'smtp.view' => 'View SMTP accounts',
            'smtp.manage' => 'Add and manage SMTP accounts',
        ],
        'analytics' => [
            'analytics.view' => 'View analytics and reports',
            'logs.view' => 'View email logs',
        ],
        'automation' => [
            'automation.view' => 'View automations',
            'automation.manage' => 'Create and manage automations',
        ],
        'settings' => [
            'settings.view' => 'View account settings',
            'settings.manage' => 'Change account settings',
            'team.manage' => 'Manage team members',
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $group => $permissions) {
            foreach ($permissions as $slug => $name) {
                Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'group' => $group],
                );
            }
        }
    }
}
