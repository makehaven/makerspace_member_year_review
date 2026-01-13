/**
 * @file
 * Popup for Year in Review.
 */

(function ($, Drupal) {
  Drupal.behaviors.yearReviewPopup = {
    attach: function (context, settings) {
      // 1. Check if already seen.
      if (localStorage.getItem('seen_year_in_review_2025') === 'true') {
        return;
      }

      // 2. Check if we are on the actual page (prevent infinite annoyance).
      if (window.location.pathname.indexOf('/year-in-review') !== -1) {
        // If they are on the page, mark as seen.
        localStorage.setItem('seen_year_in_review_2025', 'true');
        return;
      }

      // 3. Check for Bootstrap (vital).
      if (typeof bootstrap === 'undefined') {
        console.warn('Bootstrap 5 not found, Year in Review popup skipped.');
        return;
      }

      // 4. Inject Modal HTML (once).
      var modalId = 'yearReviewModal';
      // Use context to ensure we don't attach multiple times if not needed, 
      // but we append to body, so checking body global is safer.
      if ($('#' + modalId).length === 0) {
        var modalHtml = `
          <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content text-center" style="background: #111; color: #fff; border: 1px solid #444;">
                <div class="modal-header border-0 justify-content-end pb-0">
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-0 pb-5">
                  <div class="mb-3">
                    <span style="font-size: 4rem;">🎉</span>
                  </div>
                  <h2 class="modal-title mb-3" id="${modalId}Label">Your 2025 Year in Review</h2>
                  <p class="mb-4" style="font-size: 1.1rem; color: #ccc;">We've compiled your stats, badges, and making memories from the past year.</p>
                  <a href="/year-in-review" class="btn btn-primary btn-lg px-5">View My Report</a>
                </div>
              </div>
            </div>
          </div>
        `;
        $('body').append(modalHtml);

        // 5. Show Modal immediately after injection
        var modalEl = document.getElementById(modalId);
        var myModal = new bootstrap.Modal(modalEl);
        myModal.show();

        // 6. Handle "Seen" logic.
        var markSeen = function () {
          localStorage.setItem('seen_year_in_review_2025', 'true');
        };

        modalEl.addEventListener('hidden.bs.modal', markSeen);
        $(modalEl).find('.btn-primary').on('click', markSeen);
      }
    }
  };
})(jQuery, Drupal);
