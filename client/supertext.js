/* Supertext tab: show the overwrite option only when a locale with its own content is ticked,
   and a busy label while translating. Entwine re-binds when the CMS reloads the form. */
(function ($) {
  $.entwine('ss', function ($) {
    $('.supertext-targets[data-existing]').entwine({
      onmatch: function () { this._super(); this.update(); },
      onchange: function () { this.update(); },
      update: function () {
        var existing = this.data('existing') || [];
        var replacing = this.find('input:checked').toArray().some(function (box) { return existing.indexOf(box.value) !== -1; });
        $('.supertext-overwrite').closest('.field').toggleClass('supertext-hidden', !replacing).addClass('supertext-overwrite');
      }
    });
    $('button.supertext-translate, button.supertext-test').entwine({
      // The CMS only submits for buttons in the bottom toolbar; submit through it like they do.
      onclick: function (e) {
        e.preventDefault();
        if (this.is(':disabled') || (this.hasClass('supertext-translate') && !$('.supertext-targets input:checked').length)) { return false; }
        this.find('.btn__title').text(this.data('busy'));
        this.addClass('btn--loading loading');
        this.parents('form').trigger('submit', [this]);
        return false;
      }
    });

    // Export for translation / import translation: POSTed to the page editor's controller with the form's SecurityID.
    var securityId = function (el) { return $(el).closest('form').find('input[name="SecurityID"]').val() || ''; };
    $('.supertext-export-format input').entwine({
      // HTML holds one language: keep only the first ticked locale.
      onchange: function () {
        if (this.val() === 'html' && this.is(':checked')) {
          $('.supertext-export-targets input:checked').slice(1).prop('checked', false);
        }
      }
    });
    $('.supertext-export-targets input').entwine({
      onchange: function () {
        if (this.is(':checked') && $('.supertext-export-format input:checked').val() === 'html') {
          $('.supertext-export-targets input:checked').not(this).prop('checked', false);
        }
      }
    });
    $('button.supertext-export').entwine({
      onclick: function (e) {
        e.preventDefault();
        var button = this, message = $('.supertext-export-message');
        var data = new FormData();
        data.append('SecurityID', securityId(this));
        data.append('source', this.data('source'));
        data.append('format', $('.supertext-export-format input:checked').val() || 'xliff');
        $('.supertext-export-targets input:checked').each(function () { data.append('targets[]', this.value); });
        message.text('');
        button.prop('disabled', true);
        fetch(this.data('url'), { method: 'POST', body: data, credentials: 'same-origin' }).then(function (res) {
          var type = res.headers.get('Content-Type') || '';
          if (!res.ok || type.indexOf('application/json') !== -1) {
            return res.json().catch(function () { return {}; }).then(function (json) { throw new Error(json.message || ('HTTP ' + res.status)); });
          }
          var name = (/filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '') || [])[1] || 'supertext.xlf';
          return res.blob().then(function (blob) {
            var url = URL.createObjectURL(blob), a = document.createElement('a');
            a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
          });
        }).catch(function (err) { message.text(err.message); }).then(function () { button.prop('disabled', false); });
        return false;
      }
    });
    $('button.supertext-import-button').entwine({
      onclick: function (e) {
        e.preventDefault();
        var button = this, box = this.closest('.supertext-import'), result = box.find('.supertext-import-result');
        var file = box.find('.supertext-import-file')[0];
        var data = new FormData();
        data.append('SecurityID', securityId(this));
        if (file && file.files.length) { data.append('file', file.files[0]); }
        data.append('include_changed', box.find('.supertext-import-changed').is(':checked') ? '1' : '0');
        data.append('overwrite', box.find('.supertext-import-overwrite').is(':checked') ? '1' : '0');
        var label = button.text();
        button.prop('disabled', true).text(button.data('busy'));
        result.empty();
        fetch(box.data('url'), { method: 'POST', body: data, credentials: 'same-origin' })
          .then(function (res) { return res.json().catch(function () { return { message: 'HTTP ' + res.status }; }); })
          .then(function (json) {
            if (json.html) { result.html(json.html); } else { result.append($('<p class="supertext-warning">').text(json.message || '')); }
          })
          .catch(function (err) { result.append($('<p class="supertext-warning">').text(err.message)); })
          .then(function () { button.prop('disabled', false).text(label); });
        return false;
      }
    });
  });
})(jQuery);
