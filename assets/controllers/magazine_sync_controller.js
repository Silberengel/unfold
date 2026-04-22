import { Controller } from "@hotwired/stimulus";

/**
 * After first paint, refreshes Nostr magazine indices (server-side, ≤5s) and swaps header/body HTML.
 */
export default class extends Controller {
  static targets = ["headerNav", "pageBody"];
  static values = {
    page: String,
    slug: String,
    url: String,
  };

  connect() {
    this.sync();
  }

  async sync() {
    const base = this.urlValue || "/ux/magazine-sync";
    const params = new URLSearchParams();
    params.set("page", this.pageValue || "article");
    const slug = this.slugValue || "";
    if (slug !== "") {
      params.set("slug", slug);
    }
    const url = `${base}?${params.toString()}`;
    try {
      const res = await fetch(url, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      });
      if (!res.ok) {
        return;
      }
      const data = await res.json();
      if (!data.ok) {
        return;
      }
      if (this.hasHeaderNavTarget && data.header) {
        this.headerNavTarget.outerHTML = data.header;
      }
      if (this.hasPageBodyTarget && data.body) {
        this.pageBodyTarget.outerHTML = data.body;
      }
    } catch {
      /* ignore network errors */
    }
  }
}
