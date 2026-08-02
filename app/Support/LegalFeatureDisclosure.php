<?php

namespace App\Support;

class LegalFeatureDisclosure
{
    public const MARKER = 'data-wisperbot-feature-disclosure="2026-08"';

    public static function privacy(): string
    {
        return '<section '.self::MARKER.'>'
            .'<h2>Connected Services and Channel Data</h2>'
            .'<p>When a workspace connects a third-party service, we process the information needed to provide the features that workspace enables. Depending on the service and permissions granted, this may include account identifiers, profile information, messages, email headers and content, attachments, contacts, product and order details, and customer-service activity.</p>'
            .'<p>Connected services may include Gmail or Google Workspace, Microsoft or IMAP/SMTP mailboxes, Telegram Business, WhatsApp, Facebook Messenger, Instagram, website chat, SMS providers, social networks, ecommerce stores, and seller platforms such as eBay or Amazon. Each connection is also governed by the third party\'s privacy policy and the permissions approved during authorization.</p>'
            .'<h2>OAuth Credentials and Connected Accounts</h2>'
            .'<p>We store access tokens, refresh tokens, app passwords, or comparable credentials in protected form when they are required to keep a connection working. We use them only to perform requested actions such as synchronizing, displaying, sending, or replying to communications and retrieving permitted commerce data. Workspace owners can disconnect accounts, subject to retention needed for security, legal compliance, and reliable service operation.</p>'
            .'<h2>AI Automation and Knowledge Bases</h2>'
            .'<p>If a workspace enables AI features, relevant prompts, messages, knowledge-base content, catalog information, and conversation context may be sent to the AI, embedding, or vector-search provider selected by that workspace or configured for the service. This processing is used to retrieve relevant information, draft or send automated replies, summarize activity, and support human handover. AI features can produce inaccurate output, so workspace owners should configure, monitor, and review their automations.</p>'
            .'<h2>Mobile App and Notifications</h2>'
            .'<p>When the agent app or real-time notifications are used, we may process device identifiers, push-notification tokens, login and session information, delivery status, and limited message metadata needed to alert authorized team members. Notification previews may be controlled by the device, operating system, and workspace settings.</p>'
            .'<h2>Workspace Owners and End Customers</h2>'
            .'<p>A workspace owner determines which accounts, channels, contacts, team members, automations, and AI providers are connected. For customer conversations and imported business data, the workspace owner generally decides why and how that data is processed, while we process it to provide the service. Workspace owners are responsible for providing required notices, obtaining consent or another lawful basis, honoring data-subject requests, and using connected services in accordance with applicable law and provider rules.</p>'
            .'<h2>Service Providers, Transfers, and Retention</h2>'
            .'<p>We may use hosting, database, storage, email, payment, analytics, error-monitoring, push-notification, AI, and communications providers to operate WisperBot. Data may be processed in countries other than the user\'s country, with safeguards used where required. Retention varies by record type, workspace configuration, contractual need, security requirements, and law. Workspace content is deleted or anonymized when no longer required, subject to backups, fraud prevention, dispute resolution, and legal obligations.</p>'
            .'</section>';
    }

    public static function terms(): string
    {
        return '<section '.self::MARKER.'>'
            .'<h2>Scope of the Platform</h2>'
            .'<p>WisperBot may provide a shared omnichannel inbox, website chat, email mailbox management and composition, Telegram Business and Meta messaging connections, ecommerce and seller integrations, broadcasts, contacts, automations, AI-assisted replies, knowledge bases, reporting, and web or mobile agent access. Availability depends on the selected plan, configuration, region, third-party approval, and provider availability.</p>'
            .'<h2>Connected Accounts and Third-Party Services</h2>'
            .'<p>You authorize us to access connected accounts only within the permissions you approve and to perform actions you initiate or configure. You must have authority to connect each account and comply with the terms, messaging rules, acceptable-use policies, developer requirements, and commerce policies of Google, Microsoft, Telegram, Meta, eBay, Amazon, Shopify, WooCommerce, payment providers, and any other third party you use. A third party may limit, suspend, change, or withdraw its service, and we do not guarantee uninterrupted availability of an external integration.</p>'
            .'<h2>Email, Messaging, and Customer Communications</h2>'
            .'<p>You are responsible for message content, recipient lists, sender identity, consent, opt-out handling, retention, and all communications sent through your workspace. You must not use WisperBot for spam, unlawful surveillance, deceptive messaging, prohibited goods or services, or communications that violate applicable privacy, marketing, consumer-protection, or platform rules.</p>'
            .'<h2>AI-Generated Replies and Automations</h2>'
            .'<p>AI and automation output may be incomplete, inaccurate, or inappropriate for a particular customer or decision. You are responsible for your knowledge sources, prompts, provider settings, automated actions, and any review or human handover appropriate to your use case. Do not rely on AI output as professional, legal, medical, financial, or safety-critical advice. You remain responsible for communications sent from your accounts, including automatically generated replies.</p>'
            .'<h2>Mobile App and Notifications</h2>'
            .'<p>The agent app and real-time notifications are companion access methods for authorized workspace members. Delivery can depend on internet access, device permissions, app-store availability, push providers, background restrictions, and third-party services. Keep devices and login credentials secure, promptly remove access for former team members, and report suspected unauthorized use.</p>'
            .'<h2>Workspace Administration and End-Customer Data</h2>'
            .'<p>Workspace owners control team access, integrations, data sources, retention choices, and automations. You are responsible for ensuring that your collection and use of customer, mailbox, message, contact, order, and product data has a lawful basis and appropriate notices. You must respond to your customers\' privacy requests and must not instruct us or an integrated provider to process data unlawfully.</p>'
            .'</section>';
    }
}
