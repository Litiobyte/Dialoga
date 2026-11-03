=== Dialoga – Chatbot Flows Made Easy, With or Without AI ===
Contributors: litiobyte
Tags: chatbot, lead generation, conversational form, lead capture, no-code
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build easy conversational chatbot flows that capture leads, answer questions, and book appointments — with or without AI.

== Description ==

**Dialoga** is a visual chatbot builder for WordPress that helps you convert website visitors into qualified leads through interactive conversations.

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

1. Upload `dialoga` to the `/wp-content/plugins/` directory
2. Activate the plugin through the **Plugins** menu
3. Click **Run Setup Wizard** on the plugins page, or go to **Dialoga > Setup Wizard**
4. Follow the 7-step wizard to create your first chatbot with AI

= Minimum Requirements =

* WordPress 6.2+
* PHP 7.4+
* An OpenAI or Anthropic API key (for AI features)

== External services ==

Dialoga can connect to third-party AI providers when you enable the AI features. All requests are opt-in: nothing is sent until you enter an API key for a provider in the AI Settings screen and enable an AI Chat node in your flow (or run the Setup Wizard's AI-assisted flow generation).

= OpenAI =
Used when you select OpenAI as your provider in Dialoga > AI Settings and enter your own OpenAI API key.

* **What it's for:** Powers the AI Chat node's open-ended conversations, generates knowledge-base embeddings for site content, and creates the starter flow in the Setup Wizard.
* **What data is sent:** The visitor's message, the recent conversation history (last few turns), the node's system instructions, and — for the wizard — a summary of your website content that you crawled.
* **When it's sent:** Only when a visitor sends a message to an AI Chat node, when you crawl your site for the knowledge base, or when you run the Setup Wizard's flow generation.
* **Provider:** OpenAI, L.L.C. — [https://openai.com](https://openai.com)
* **Terms of Use:** [https://openai.com/policies/terms-of-use](https://openai.com/policies/terms-of-use)
* **Privacy Policy:** [https://openai.com/policies/privacy-policy](https://openai.com/policies/privacy-policy)

= Anthropic =
Used when you select Anthropic as your provider in Dialoga > AI Settings and enter your own Anthropic API key.

* **What it's for:** Same as OpenAI above — powers AI Chat conversations and generates flows in the Setup Wizard when Anthropic is the selected provider.
* **What data is sent:** The visitor's message, recent conversation history, the node's system instructions, and (wizard only) your crawled site summary.
* **When it's sent:** Only when a visitor interacts with an AI Chat node or you run the Setup Wizard while Anthropic is selected.
* **Provider:** Anthropic, PBC — [https://anthropic.com](https://anthropic.com)
* **Terms of Use:** [https://www.anthropic.com/legal/consumer-terms](https://www.anthropic.com/legal/consumer-terms)
* **Privacy Policy:** [https://www.anthropic.com/legal/privacy](https://www.anthropic.com/legal/privacy)

If you do not enter an API key and do not use AI Chat nodes, Dialoga makes no external calls at all.

== Source code ==

The readable React Flow canvas source is included in `src/canvas/`. The production assets in `assets/js/build/` are generated with the pinned dependencies in `package-lock.json`.

To reproduce the assets, install Node.js 22 and run `npm ci && npm run build` from the plugin directory. Third-party license notices are included in `THIRD-PARTY-LICENSES.txt`.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes, for AI-powered features you need an API key from [OpenAI](https://platform.openai.com) or [Anthropic](https://console.anthropic.com). The Setup Wizard walks you through getting one. Typical cost is $0.01-0.05 per conversation.

= Can I use Dialoga without AI? =

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

= 1.0.0 =
First stable release. Safe to upgrade from any 0.x version. No breaking changes to flows, leads, or settings.
