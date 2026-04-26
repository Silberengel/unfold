import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  connect() {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/service-worker.js')
        .then(() => {
          /* optional: console.debug('SW registered') */
        })
        .catch(err => console.error('SW failed:', err));
    }
  }
}
