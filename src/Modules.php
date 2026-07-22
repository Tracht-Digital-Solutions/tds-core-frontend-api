<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi;

use Tds\Ext\Billing\BillingModule;
use Tds\Ext\BlogCms\BlogCmsModule;
use Tds\Ext\ContactTickets\ContactTicketsModule;
use Tds\Ext\Customers\CustomersModule;
use Tds\Ext\Documents\DocumentsModule;
use Tds\Ext\Lexware\LexwareModule;
use Tds\Ext\LiveChatCta\LiveChatCtaModule;
use Tds\Ext\Messages\MessagesModule;
use Tds\Ext\Projects\ProjectsModule;
use Tds\Ext\SupportTickets\SupportTicketsModule;
use Tds\Ext\TimeTracker\TimeTrackerModule;
use Tds\Ext\Tools\ToolsModule;
use Tds\Ext\WebsiteCms\WebsiteCmsModule;
use Tds\Frontend\Contract\Module;

/**
 * The enabled-module list for THIS API build — the backend twin of the
 * frontend product's `astro.config` extension list. The base composes exactly
 * these through the ModuleRegistry (dependency-ordered, collision-checked).
 *
 * The base MUST work with an empty list; extensions are purely additive. The
 * admin and customer API targets differ only in what this returns.
 */
final class Modules
{
    /** @return Module[] */
    public static function enabled(): array
    {
        return [
            new TimeTrackerModule(),
            new CustomersModule(),
            new BillingModule(),
            new LexwareModule(),
            new ToolsModule(),
            new MessagesModule(),
            new ProjectsModule(),
            new DocumentsModule(),
            new SupportTicketsModule(),
            new ContactTicketsModule(),
            new LiveChatCtaModule(),
            new WebsiteCmsModule(),
            new BlogCmsModule(),
        ];
    }
}
