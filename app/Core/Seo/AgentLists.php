<?php

namespace App\Core\Seo;

/**
 * Who the two AI lists start out naming.
 *
 * A DEFAULT, not a truth: these names are published by the companies that run
 * the crawlers and change with no notice, which is exactly why the shop keeps
 * them in an editable setting (`ai_assistant_agents` / `ai_training_agents`)
 * rather than reading this class at runtime. A shop that has never touched the
 * setting gets a sensible file on day one; one that has is never overwritten
 * by an engine update.
 *
 * The split is by what the fetch is FOR, not by who owns it — the same company
 * appears in both lists under different names, and that is the point: a shop
 * can welcome the assistant answering a customer's question and still refuse
 * the crawler filling a training set.
 */
class AgentLists
{
    /** Fetch a page because a person asked something about it, and can send that person back. */
    public const ASSISTANTS = <<<'TXT'
        ChatGPT-User
        OAI-SearchBot
        Claude-User
        Claude-SearchBot
        PerplexityBot
        Perplexity-User
        DuckAssistBot
        MistralAI-User
        Meta-ExternalFetcher
        Amazonbot
        TXT;

    /** Collect pages in bulk to train on. */
    public const TRAINING = <<<'TXT'
        GPTBot
        ClaudeBot
        anthropic-ai
        Google-Extended
        Applebot-Extended
        CCBot
        Bytespider
        meta-externalagent
        FacebookBot
        YandexAdditional
        YandexAdditionalBot
        cohere-ai
        Diffbot
        Omgilibot
        Timpibot
        ImagesiftBot
        AI2Bot
        Webzio-Extended
        TXT;
}
