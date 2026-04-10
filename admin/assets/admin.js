document.addEventListener('click', function (event) {
  var trigger = event.target.closest('[data-copy-target]');
  if (!trigger) {
    return;
  }

  var targetId = trigger.getAttribute('data-copy-target');
  var input = document.getElementById(targetId);
  if (!input) {
    return;
  }

  input.focus();
  input.select();
  input.setSelectionRange(0, input.value.length);

  navigator.clipboard.writeText(input.value).then(function () {
    var original = trigger.textContent;
    trigger.textContent = 'Copied';
    window.setTimeout(function () {
      trigger.textContent = original;
    }, 1200);
  }).catch(function () {
    document.execCommand('copy');
  });
});

document.addEventListener('submit', function (event) {
  var form = event.target;
  if (form.matches('[data-confirm-reset]')) {
    var ok = window.confirm('Clear all raw and unique test data? This cannot be undone.');
    if (!ok) {
      event.preventDefault();
    }
  }
});
