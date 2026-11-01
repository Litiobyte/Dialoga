=== DialogaWP – Chatbot & Conversational Lead Capture Forms ===
Contributors: litiobyte
Tags: chatbot, lead generation, ai chat, conversational form, lead capture
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build conversational chatbot flows that capture leads, answer questions, and book appointments — powered by AI.

== Description ==

**DialogaWP** is a visual chatbot builder for WordPress that helps you convert website visitors into qualified leads through interactive conversations.

No coding required. Drag and drop nodes to create a conversation flow, connect your AI provider, and deploy a chat widget on your site in minutes.

= Key Features =

* **Visual Flow Builder** — Drag-and-drop canvas with real-time preview
* **AI-Powered Chat** — Connect OpenAI or Anthropic for intelligent conversations
* **Lead Capture Forms** — Collect name, email, phone through conversation
* **Smart Branching** — Route visitors based on their responses
* **AI Knowledge Base** — Crawl your website so the AI answers accurately
* **Setup Wizard** — Get your first chatbot running in 5 minutes
* **Session Persistence** — Conversations continue across page navigations
* **Mobile Responsive** — Chat widget works on all devices
* **Analytics** — Track conversations, leads, and conversion rates
* **Lead Tags** — Auto-tag and filter leads by flow, behavior, or custom rules
* **Variables** — Store answers and personalize later messages with {{variables}}
* **CSV Export** — Export leads for use in any CRM

= How It Works =

1. **Install & Activate** — Install the plugin and run the Setup Wizard
2. **Connect AI** — Add your OpenAI or Anthropic API key
3. **Crawl Your Site** — We learn about your business from your website
4. **Set Goals** — Choose: capture leads, book appointments, support, or information
5. **Launch** — Your chatbot is live and capturing leads

= Node Types =

* **Message** — Display a message to the visitor
* **Question** — Ask questions with buttons or free text input
* **Branch** — Route the conversation based on conditions (AND/OR logic)
* **Action** — Capture leads or redirect with countdown
* **AI Chat** — Open-ended AI conversation with custom instructions
* **Loop** — Jump back to any earlier point in the flow



== Installation ==

1. Upload `dialogawp` to the `/wp-content/plugins/` directory
2. Activate the plugin through the **Plugins** menu
3. Click **Run Setup Wizard** on the plugins page, or go to **DialogaWP > Setup Wizard**
4. Follow the 7-step wizard to create your first chatbot

= Minimum Requirements =

* WordPress 6.2+
* PHP 7.4+
* An OpenAI or Anthropic API key (for AI features)

== External services ==

DialogaWP can connect to third-party AI providers when you enable the AI features. All requests are opt-in: nothing is sent until you enter an API key for a provider in the AI Settings screen and enable an AI Chat node in your flow (or run the Setup Wizard's AI-assisted flow generation).

= OpenAI =
Used when you select OpenAI as your provider in DialogaWP > AI Settings and enter your own OpenAI API key.

* **What it's for:** Powers the AI Chat node's open-ended conversations, generates knowledge-base embeddings for site content, and creates the starter flow in the Setup Wizard.
* **What data is sent:** The visitor's message, the recent conversation history (last few turns), the node's system instructions, and — for the wizard — a summary of your website content that you crawled.
* **When it's sent:** Only when a visitor sends a message to an AI Chat node, when you crawl your site for the knowledge base, or when you run the Setup Wizard's flow generation.
* **Provider:** OpenAI, L.L.C. — [https://openai.com](https://openai.com)
* **Terms of Use:** [https://openai.com/policies/terms-of-use](https://openai.com/policies/terms-of-use)
* **Privacy Policy:** [https://openai.com/policies/privacy-policy](https://openai.com/policies/privacy-policy)

= Anthropic =
Used when you select Anthropic as your provider in DialogaWP > AI Settings and enter your own Anthropic API key.

* **What it's for:** Same as OpenAI above — powers AI Chat conversations and generates flows in the Setup Wizard when Anthropic is the selected provider.
* **What data is sent:** The visitor's message, recent conversation history, the node's system instructions, and (wizard only) your crawled site summary.
* **When it's sent:** Only when a visitor interacts with an AI Chat node or you run the Setup Wizard while Anthropic is selected.
* **Provider:** Anthropic, PBC — [https://anthropic.com](https://anthropic.com)
* **Terms of Use:** [https://www.anthropic.com/legal/consumer-terms](https://www.anthropic.com/legal/consumer-terms)
* **Privacy Policy:** [https://www.anthropic.com/legal/privacy](https://www.anthropic.com/legal/privacy)

If you do not enter an API key and do not use AI Chat nodes, DialogaWP makes no external calls at all.

== Source code ==

The readable React Flow canvas source is included in `src/canvas/`. The production assets in `assets/js/build/` are generated with the pinned dependencies in `package-lock.json`.

To reproduce the assets, install Node.js 22 and run `npm ci && npm run build` from the plugin directory. Third-party license notices are included in `THIRD-PARTY-LICENSES.txt`.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes, for AI-powered features you need an API key from [OpenAI](https://platform.openai.com) or [Anthropic](https://console.anthropic.com). The Setup Wizard walks you through getting one. Typical cost is $0.01-0.05 per conversation.

= Can I use DialogaWP without AI? =

Yes! You can build flows using Message, Question, Branch, and Action nodes without any AI. The AI Chat node is optional.

= Is the chat widget mobile responsive? =

Yes, the chat widget is fully responsive and works on all screen sizes.

= How many leads can I capture? =

There is no limit on the number of leads you can capture with the free version.

= What's the difference between Free and Pro? =

The free version is fully functional: the complete flow builder, unlimited flows (active at the same time if you want), AI chat, lead capture with tagging and CSV export, the setup wizard, and analytics. Pro is a separate plugin that adds A/B testing and routing rules, webhooks, lead qualification scoring, surveys, page-level targeting, and advanced analytics reports.

= Can I export my leads? =

Yes, you can export leads to CSV from the Leads page in your WordPress admin.

== Changelog ==

= 1.0.6 =
* Organized Settings into accessible tabs while preserving all fields in a single save operation.
* Added bundled Spanish translations for the included administrative screens, following the WordPress user/site locale with English fallback.
* Added individual and bulk JSON flow export/import with server-side validation and inactive imported copies.
* Added in-browser full-screen canvas mode with an exit button and Escape support.
* Improved escaping of dynamic flow editor fields and preserved current graph state during export.

= 1.0.5 =
* The branch node no longer writes a dozen trace lines to the PHP error log every time a visitor answers a question. That output included the visitor's own typed response, and it is now behind WP_DEBUG like the rest of the plugin's debug logging.
* Removed a stray browser-console message that appeared whenever a visitor restarted a chat.

= 1.0.4 =
* Fixed: chats opened with "Configuration error. Please contact support." instead of the first message. Saving a flow was lowercasing every JSON key in it, so `nodeType` became `nodetype` and the frontend could no longer tell what any node was. Branch and loop edges lost their handle bindings the same way. Flows already damaged on disk are repaired automatically on update.
* Fixed: every DialogaWP admin screen rendered unstyled — most visibly the flow builder, whose colour-coded node buttons fell back to plain grey. Page CSS was being attached after WordPress had already printed the stylesheets, so none of it reached the browser. It now ships as real stylesheets enqueued per screen.
* Fixed: saving a flow collapsed multi-line chat messages onto a single line.

= 1.0.3 =
* Removed output buffering from every admin template. The inline CSS/JS blocks that were previously captured from a buffer are now plain nowdoc strings, so there is no buffer a hook or early return could leave open.
* The two admin scripts that need a nonce and the AJAX URL now substitute them into the script body with strtr() after the fact, keeping the JavaScript free of inline PHP.

= 1.0.2 =
* Removed the single-active-flow restriction. Any number of flows can now be active at the same time in the free plugin.
* Removed the setup wizard block that stopped you creating a second chatbot.
* The Loop node's loopback message and pre-loop delay are now fully editable and fully functional — the fields were previously disabled behind a "Pro only" overlay and ignored at runtime.
* AI chat per-session and per-IP ceilings raised to abuse-protection levels (50 replies per session, 100 calls per IP per hour) and are the same for every install. The dashboard notice that counted people hitting the old limit has been removed.
* Removed the locked "Pro" goal cards from the setup wizard and the "Managed AI" placeholder. Extensions add their own via the `dialoga_wp_wizard_extra_goal_cards` action.
* Corrected stale "Pro feature" labels on Send Email and Set Variable — both have always worked in the free plugin.
* Review prompt now links to the full, unfiltered reviews page instead of a 5-star-filtered view.

= 1.0.1 =
* Compliance: removed the free-vs-Pro drop-off row limit on the dashboard (all installs now see the top 3). The Pro upsell copy about "more drop-off points" was tied to that limit and has been removed.
* Compliance: added a nonce check on the Leads CSV export handler so the download can only be triggered from a real click on the Leads screen.
* Security: wizard save-state, chat-widget transcript saves, survey responses, and lead-notification email transcripts now run every string in the payload through sanitize_text_field before touching the database or email body.
* Compliance: moved the flow-builder HTML templates and the chat-widget helper script off inline &lt;script&gt; tags. Templates now travel via wp_localize_script; the widget helper JS is attached via wp_add_inline_script on the widget handle.
* Update: Chart.js bundled with the analytics dashboard bumped from 4.5.0 to 4.5.1.

= 1.0.0 =
* First stable release. Everything from the 0.x preview series is now considered production-ready.
* New: Full Flow Builder with drag-and-drop canvas, question/message/branch/action/AI-chat/loop nodes
* New: Setup Wizard — 7-step guided creation using your crawled site content
* New: AI Chat node — open-ended conversations powered by OpenAI or Anthropic
* New: Website Crawler — build a knowledge base so the AI answers accurately about your business
* New: Analytics dashboard with Chart.js visualizations (daily trend, conversion split, top sources)
* New: Lead capture with tagging, filtering, transcripts, and CSV export
* New: Variables — save any answer as `{{name}}` and reference it later in the flow
* New: Global modal system replacing browser alerts
* New: Two-factor authentication support for the DialogaWP account portal
* Improved: Flow Builder UI — full-viewport canvas, wrapping toolbars, viewport-anchored node editor
* Improved: Chat widget mobile responsiveness across all breakpoints
* Improved: Session persistence for cross-page conversations

= 0.36.0 =
* New: Analytics charts — Chart.js line chart for daily conversation trend, doughnut for conversion split, horizontal bar for top source pages
* New: Pro Advanced Analytics Visual Funnel — B2C-style SVG cone with rounded corners, color-coded by drop severity, node type badges, per-stage description panels
* New: Pro Advanced Analytics Path Flow tab — Sankey diagram (chartjs-chart-sankey) colored by node type, with Top Paths table beside it
* New: Pro Advanced Analytics Heatmap tab — 7x24 day-of-week × hour grid of conversation volume with yellow→orange→red heat gradient
* New: Analytics date presets — Today, Last 7/30/90 days, Month to date, Last month, Year to date, All time
* New: Loading overlays on Analytics and Pro Analytics pages when filters/tabs change
* New: Lead detail modal — full contact info, conversation metadata, and full transcript viewer on the Leads page
* New: Tag autocomplete datalist — existing tags surface as suggestions when adding new ones
* New: Tag save spinner — animated progress indicator during tag updates
* New: Flow-name auto-tag moved to free tier — every captured lead tagged with its flow name
* Improved: Funnel detail table — renamed "Completion" to "Views vs. Peak" (scale always 0-100%), fixed double-negative drop display for loop/merge stages
* Improved: Flow selection preserved across Pro Analytics tabs for faster multi-report browsing
* Improved: Analytics menu now sits just above Advanced Analytics (free + Pro grouped together)
* Improved: Variable interpolation in analytics labels (`{{var}}` no longer shows raw in drop-off labels)
* Fixed: CSV export on Leads page no longer returns HTML — moved handler to admin_init so headers flush cleanly
* Fixed: Transcripts now save on chat close, flow end, AND page unload (via sendBeacon). Previously only saved on lead capture, so most conversations had no transcript
* Fixed: Export CSV button relocated from page heading to filter bar where users expect it

= 0.35.0 =
* New: Redesigned admin dashboard with stat cards, recent leads, and quick links
* New: Setup Wizard with 7-step guided setup flow
* New: Global modal system replacing all browser alerts
* New: Empty state with DialogaWP logo in flow builder
* New: Mobile/tablet "Laptop or Desktop Required" message
* New: Auto-naming flows (ChatFlow 1, ChatFlow 2, etc.)
* Improved: Bubble nudge animation
* Improved: Variable storage and interpolation in flows

= 0.34.0 =
* New: Setup Wizard — 7-step guided chatbot creation
* New: Tags — auto-tag leads by flow name, manual tagging, colored pills, filter bar
* New: Tag Lead action node (Pro) — auto-tag based on flow path
* New: Webhooks (Pro) — per-flow webhook URL with HMAC-SHA256 signing
* New: Survey action node (Pro) — rating scales, NPS, multiple choice, open text
* New: Qualify Leads node (Pro) — scoring questions, auto-tag hot/warm/cold
* New: Webhook canvas node (Pro) — fire data to external URLs at any flow point
* New: Page-level targeting (Pro) — show different flows on different pages
* New: Modal system — replaces all browser alerts with styled modals
* New: Flow builder empty state with setup wizard link
* New: Auto-naming flows (ChatFlow 1, ChatFlow 2, etc.)
* New: Mobile builder message (laptop/desktop required)
* Improved: AI flow generation receives goals, business info, and industry context
* Improved: Webhook node in builder palette with Pro badge
* Improved: Visibility dropdown cleaned up and widened
* Fixed: Chat widget name collision (dialoga-wpWidget/dialoga-wpConfig)
* Fixed: Start node auto-connects on flow load
* Fixed: No auto-popup editor on new flow creation

= 0.33.0 =
* New: Variable storage — save answers as named variables
* New: Bubble nudge animation for new visitors

= 0.32.0 =
* New: Full analytics event tracking
* New: Test mode
* New: Conversation persistence to database
* New: Single-flow restriction for free tier

= 0.31.0 =
* New: AI Chat node type
* New: Loop node type
* New: AI Flow Wizard — generate flows from crawled website content

= 0.30.0 =
* New: Website crawler for AI knowledge base
* New: AI settings page

= 0.29.0 =
* New: Action nodes — capture lead forms and redirect with countdown

== Upgrade Notice ==

= 1.0.3 =
Internal change only: admin inline styles/scripts no longer use output buffering. No functional or visual differences.

= 1.0.2 =
Removes all remaining feature restrictions: unlimited active flows, unlocked Loop node options, and much higher AI chat ceilings. Safe upgrade.

= 1.0.1 =
Compliance and security release addressing WordPress.org reviewer feedback. Safe upgrade from 1.0.0 — no breaking changes.

= 1.0.0 =
First stable release. Safe to upgrade from any 0.x version. No breaking changes to flows, leads, or settings.
