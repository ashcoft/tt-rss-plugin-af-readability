<?php
require_once __DIR__ . "/vendor/autoload.php";

use \fivefilters\Readability\Readability;

/**
 * Af_Readability - Tiny Tiny RSS plugin for extracting full article content
 * using Readability.php to inline article text into feed entries.
 */
class Af_Readability extends Plugin {

    /** @var PluginHost $host */
    private $host;

    /**
     * Get plugin info.
     *
     * @return array Plugin info array
     */
    public function about() {
        return array(null,
            "Try to inline article content using Readability",
            "fox");
    }

    /**
     * Get plugin flags.
     *
     * @return array Plugin flags
     */
    public function flags() {
        return array("needs_curl" => true);
    }

    /**
     * Save plugin settings.
     *
     * @return void
     */
    public function save() {
        $enable_share_anything = checkbox_to_sql_bool($_POST["enable_share_anything"] ?? "");

        $this->host->set($this, "enable_share_anything", $enable_share_anything);

        echo __("Data saved.");
    }

    /**
     * Initialize plugin hooks.
     *
     * @param PluginHost $host
     * @return void
     */
    public function init($host)
    {
        $this->host = $host;

        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        $host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);

        // Note: we have to install the hook even if disabled because init() is being run before plugin data has loaded
        // so we can't check for our storage-set options here
        $host->add_hook($host::HOOK_GET_FULL_TEXT, $this);

        $host->add_filter_action($this, "action_inline", __("Inline content"));
        $host->add_filter_action($this, "action_inline_append", __("Append content"));
    }

    /**
     * Get JavaScript for the plugin.
     *
     * @return string JavaScript content
     */
    public function get_js() {
        return file_get_contents(__DIR__ . "/init.js");
    }

    /**
     * Hook to add article button.
     *
     * @param array $line Article line data
     * @return string HTML for the button
     */
    public function hook_article_button($line) {
        return "<i class='material-icons' onclick=\"Plugins.Af_Readability.embed(".$line["id"].")\"
            style='cursor : pointer' title=\"".__('Toggle full article text')."\">description</i>";
    }

    /**
     * Hook to add preferences tab.
     *
     * @param string $args Tab identifier
     * @return void
     */
    public function hook_prefs_tab($args) {
        if ($args != "prefFeeds") {
            return;
        }

        $enable_share_anything = sql_bool_to_bool($this->host->get($this, "enable_share_anything"));

        ?>
        <div dojoType='dijit.layout.AccordionPane'
            title="<i class='material-icons'>extension</i> <?= __('Readability settings (af_readability)') ?>">

            <?= format_notice("Enable for specific feeds in the feed editor.") ?>

            <form dojoType='dijit.form.Form'>

                <?= \Controls\pluginhandler_tags($this, "save") ?>

                <script type="dojo/method" event="onSubmit" args="evt">
                    evt.preventDefault();
                    if (this.validate()) {
                        Notify.progress('Saving data...', true);
                        xhr.post("backend.php", this.getValues(), (reply) => {
                            Notify.info(reply);
                        })
                    }
                </script>

                <fieldset>
                    <label class='checkbox'>
                        <?= \Controls\checkbox_tag("enable_share_anything", $enable_share_anything) ?>
                        <?= __("Provide full-text services to core code (bookmarklets) and other plugins") ?>
                    </label>
                </fieldset>

                <hr/>

                <?= \Controls\submit_tag(__("Save")) ?>
            </form>

            <?php
                /* cleanup */
                $enabled_feeds = $this->filter_unknown_feeds(
                    $this->get_stored_array("enabled_feeds"));

                $append_feeds = $this->filter_unknown_feeds(
                    $this->get_stored_array("append_feeds"));

                $this->host->set($this, "enabled_feeds", $enabled_feeds);
                $this->host->set($this, "append_feeds", $append_feeds);
            ?>

            <?php if (count($enabled_feeds) > 0) { ?>
                <hr/>
                <h3><?= __("Currently enabled for (click to edit):") ?></h3>

                <ul class='panel panel-scrollable list list-unstyled'>
                    <?php foreach ($enabled_feeds as $f) { ?>
                        <li>
                            <?php if (Feeds::_has_icon($f)) { ?>
                                <img src='<?= Feeds::_get_icon_url($f) ?>' style="max-height: 20px" />
                            <?php } else { ?> <i class='material-icons'>rss_feed</i> <?php } ?>
                            <a href='#'    onclick="CommonDialogs.editFeed(<?= $f ?>)">
                                    <?= Feeds::_get_title($f, $this->host->get_owner_uid()) . " " . (in_array($f, $append_feeds) ? __("(append)") : "") ?>
                            </a>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * Hook to add feed edit preferences.
     *
     * @param int $feed_id Feed ID
     * @return void
     */
    public function hook_prefs_edit_feed($feed_id) {
        $enabled_feeds = $this->get_stored_array("enabled_feeds");
        $append_feeds = $this->get_stored_array("append_feeds");
        ?>

        <header><?= __("Readability") ?></header>
        <section>
            <fieldset>
                <label class='checkbox'>
                    <?= \Controls\checkbox_tag("af_readability_enabled", in_array($feed_id, $enabled_feeds)) ?>
                    <?= __('Inline article content') ?>
                </label>
            </fieldset>
            <fieldset>
                <label class='checkbox'>
                    <?= \Controls\checkbox_tag("af_readability_append", in_array($feed_id, $append_feeds)) ?>
                    <?= __('Append to summary, instead of replacing it') ?>
                </label>
            </fieldset>
        </section>
        <?php
    }

    /**
     * Hook to save feed preferences.
     *
     * @param int $feed_id Feed ID
     * @return void
     */
    public function hook_prefs_save_feed($feed_id) {
        $enabled_feeds = $this->get_stored_array("enabled_feeds");
        $append_feeds = $this->get_stored_array("append_feeds");

        $enable = checkbox_to_sql_bool($_POST["af_readability_enabled"] ?? "");
        $append = checkbox_to_sql_bool($_POST["af_readability_append"] ?? "");

        $enable_key = array_search($feed_id, $enabled_feeds);
        $append_key = array_search($feed_id, $append_feeds);

        if ($enable) {
            if ($enable_key === false) {
                array_push($enabled_feeds, $feed_id);
            }
        } else {
            if ($enable_key !== false) {
                unset($enabled_feeds[$enable_key]);
            }
        }

        if ($append) {
            if ($append_key === false) {
                array_push($append_feeds, $feed_id);
            }
        } else {
            if ($append_key !== false) {
                unset($append_feeds[$append_key]);
            }
        }

        $this->host->set($this, "enabled_feeds", $enabled_feeds);
        $this->host->set($this, "append_feeds", $append_feeds);
    }

    /**
     * Hook for article filter actions.
     *
     * @param array $article Article data
     * @param string $action Action name
     * @return array Modified article
     */
    public function hook_article_filter_action($article, $action) {
        switch ($action) {
            case "action_inline":
                return $this->process_article($article, false);
            case "action_append":
                return $this->process_article($article, true);
            default:
                return $article;
        }
    }

    /**
     * @param string $url
     * @return string|false
     */
    public function extract_content(string $url) {
        $result = false;

        $tmp = UrlHelper::fetch([
            "url" => $url,
            "http_accept" => "text/*",
            "type" => "text/html"]);

        if ($tmp && mb_strlen($tmp) < 1024 * 500) {
            try {
                $result = $this->tryExtractContent($tmp, $url);
            } catch (Throwable $e) {
                error_log('[Af_Readability] ' . get_class($e) . ' for URL "' . $url . '": ' . $e->getMessage());
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Try to extract article content with fallback strategy
     */
    private function tryExtractContent(string $html, string $url): string|false {
        $effectiveUrl = UrlHelper::$fetch_effective_url ?: $url;

        // First attempt with standard threshold
        $config = new \fivefilters\Readability\Configuration(
            fixRelativeURLs: true,
            originalURL: $effectiveUrl,
            charThreshold: 200,
            keepClasses: true,
            stripUnlikelyCandidates: true,
            weightClasses: true,
            cleanConditionally: true,
        );

        $r = new Readability($config);
        $article = $r->parse($html);

        if (!$article || !$article->hasContent()) {
            return false;
        }

        $content = $this->fixContentUrls($article->contentElement, $effectiveUrl);
        $contentLength = mb_strlen(strip_tags($content));

        // If content is too short, retry with even lower threshold and relaxed filtering
        if ($contentLength < 200) {
            $config2 = new \fivefilters\Readability\Configuration(
                fixRelativeURLs: true,
                originalURL: $effectiveUrl,
                charThreshold: 100,
                keepClasses: true,
                stripUnlikelyCandidates: false,
                weightClasses: false,
                cleanConditionally: false,
            );
            $r2 = new Readability($config2);
            $article2 = $r2->parse($html);

            if ($article2 && $article2->hasContent()) {
                $content2 = $this->fixContentUrls($article2->contentElement, $effectiveUrl);
                if (mb_strlen(strip_tags($content2)) > $contentLength) {
                    return $content2;
                }
            }
        }

        return $content;
    }

    /**
     * Fix relative URLs in content element
     */
    private function fixContentUrls(\Dom\Element $contentElement, string $baseUrl): string {
        $tmpxpath = new \Dom\XPath($contentElement->ownerDocument);
        $entries = $tmpxpath->query('.//a[@href]|.//img[@src]', $contentElement);

        foreach ($entries as $entry) {
            $element = $entry instanceof \Dom\Element ? $entry : null;
            if (!$element) continue;

            if ($element->hasAttribute("href")) {
                $element->setAttribute("href",
                    UrlHelper::rewrite_relative($baseUrl, $element->getAttribute("href")));
            }

            if ($element->hasAttribute("src")) {
                if ($element->hasAttribute("data-src")) {
                    $src = $element->getAttribute("data-src");
                } else {
                    $src = $element->getAttribute("src");
                }
                $element->setAttribute("src",
                    UrlHelper::rewrite_relative($baseUrl, $src));
            }
        }

        return $contentElement->innerHTML;
    }

    /**
     * @param array<string, mixed> $article
     * @param bool $append_mode
     * @return array<string,mixed>
     * @throws PDOException
     */
    public function process_article(array $article, bool $append_mode) : array {

        $extracted_content = $this->extract_content($article["link"]);

        # let's see if there's anything of value in there
        $content_test = trim(strip_tags(Sanitizer::sanitize($extracted_content)));

        if ($content_test) {
            if ($append_mode) {
                $article["content"] .= "<hr/>" . $extracted_content;
            } else {
                $article["content"] = $extracted_content;
            }
        }

        return $article;
    }

    /**
     * @param string $name
     * @return array<int|string, mixed>
     * @throws PDOException
     * @deprecated
     */
    private function get_stored_array(string $name) : array {
        return $this->host->get_array($this, $name);
    }

    /**
     * Hook for article filtering.
     *
     * @param array $article Article data
     * @return array Modified article
     */
    public function hook_article_filter($article) {

        $enabled_feeds = $this->get_stored_array("enabled_feeds");
        $append_feeds = $this->get_stored_array("append_feeds");

        $feed_id = $article["feed"]["id"];

        if (!in_array($feed_id, $enabled_feeds)) {
            return $article;
        }

        return $this->process_article($article, in_array($feed_id, $append_feeds));

    }

    /**
     * Hook for getting full text of an article.
     *
     * @param string $link Article URL
     * @return string|false Extracted content or false
     */
    public function hook_get_full_text($link) {
        $enable_share_anything = $this->host->get($this, "enable_share_anything");

        if ($enable_share_anything) {
            $extracted_content = $this->extract_content($link);

            # let's see if there's anything of value in there
            $content_test = trim(strip_tags(Sanitizer::sanitize($extracted_content)));

            if ($content_test) {
                return $extracted_content;
            }
        }

        return false;
    }

    /**
     * Get API version.
     *
     * @return int API version number
     */
    public function api_version() {
        return 2;
    }

    /**
     * @param array<int> $enabled_feeds
     * @return array<int>
     * @throws PDOException
     */
    private function filter_unknown_feeds(array $enabled_feeds) : array {
        $tmp = array();

        foreach ($enabled_feeds as $feed) {

            $sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
            $sth->execute([$feed, $_SESSION['uid']]);

            if ($row = $sth->fetch()) {
                array_push($tmp, $feed);
            }
        }

        return $tmp;
    }

    /**
     * Embed handler for article content.
     *
     * @return void
     */
    public function embed() : void {
        // Enforce authentication before processing request
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['uid'])) {
            http_response_code(401);
            print json_encode(["error" => "authentication required"]);
            return;
        }

        $article_id = (int) $_REQUEST["id"];

        $sth = $this->pdo->prepare("SELECT link FROM ttrss_entries WHERE id = ?");
        $sth->execute([$article_id]);

        $ret = [];

        if ($row = $sth->fetch()) {
            $ret["content"] = Sanitizer::sanitize($this->extract_content($row["link"]));
        }

        print json_encode($ret);
    }

}
