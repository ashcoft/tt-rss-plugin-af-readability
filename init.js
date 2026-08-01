/* global xhr, App, Plugins, Article, Notify */
/**
 * Tiny Tiny RSS plugin for extracting full article content using Readability.
 * innerHTML is used intentionally to render sanitized HTML content from the
 * Readability parser (server-side). This is a standard pattern for RSS readers
 * where article content is expected to be safe HTML extracted from web pages.
 */

Plugins.Af_Readability = {
    orig_attr_name: 'data-readability-orig-content',
    embed: function(id) {
        var self = this;

        const content = document.querySelector(App.isCombinedMode() ? `.cdm[data-article-id="${id}"] .content-inner` :
            `.post[data-article-id="${id}"] .content`);

        if (content.hasAttribute(self.orig_attr_name)) {
            // Restore original content from stored attribute
            content.innerHTML = content.getAttribute(self.orig_attr_name);
            content.removeAttribute(self.orig_attr_name);

            if (App.isCombinedMode()) Article.cdmMoveToId(id);

            return;
        }

        Notify.progress("Loading, please wait...");

        xhr.json("backend.php", App.getPhArgs("af_readability", "embed", {id: id}), (reply) => {

            if (content && reply.content) {
                content.setAttribute(self.orig_attr_name, content.innerHTML);
                // Render HTML content from Readability parser (sanitized server-side)
                content.innerHTML = reply.content;
                Notify.close();

                if (App.isCombinedMode()) Article.cdmMoveToId(id);

            } else {
                Notify.error("Unable to fetch full text for this article");
            }
        });
    }
};
