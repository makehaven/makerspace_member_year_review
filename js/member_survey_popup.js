/**
 * @file
 * Popup for the 2026 member survey.
 */

(function ($, Drupal) {
  Drupal.behaviors.memberSurveyPopup = {
    attach: function () {
      var seenKey = 'seen_2026_member_survey_popup';
      if (localStorage.getItem(seenKey) === 'true') {
        return;
      }

      var surveyPath = '/form/2026-member-survey';
      if (window.location.pathname.indexOf(surveyPath) === 0) {
        localStorage.setItem(seenKey, 'true');
        return;
      }

      if (typeof bootstrap === 'undefined') {
        console.warn('Bootstrap 5 not found, member survey popup skipped.');
        return;
      }

      var modalId = 'memberSurvey2026Modal';
      if ($('#' + modalId).length !== 0) {
        return;
      }

      var modalHtml =
        '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="' + modalId + 'Label" aria-hidden="true">' +
          '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content text-center">' +
              '<div class="modal-header border-0 justify-content-end pb-0">' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
              '</div>' +
              '<div class="modal-body pt-0 pb-4 px-4">' +
                '<h2 class="modal-title mb-3" id="' + modalId + 'Label">2026 Member Survey</h2>' +
                '<p class="mb-4">Please take a minute to share your feedback. Your response helps shape this year at MakeHaven.</p>' +
                '<a href="' + surveyPath + '" class="btn btn-primary btn-lg px-4">Take the Survey</a>' +
              '</div>' +
            '</div>' +
          '</div>' +
        '</div>';

      $('body').append(modalHtml);

      var modalEl = document.getElementById(modalId);
      var modal = new bootstrap.Modal(modalEl);
      modal.show();

      var markSeen = function () {
        localStorage.setItem(seenKey, 'true');
      };

      modalEl.addEventListener('hidden.bs.modal', markSeen);
      $(modalEl).find('.btn-primary').on('click', markSeen);
    }
  };
})(jQuery, Drupal);
