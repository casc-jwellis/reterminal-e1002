// Small progressive enhancements for the admin pages. Everything works without it.
document.addEventListener('DOMContentLoaded', () => {
  // Show a block only while a <select> has a given value.
  document.querySelectorAll('select[data-controls]').forEach((select) => {
    const target = document.getElementById(select.dataset.controls);
    if (!target) return;
    const update = () => { target.hidden = select.value !== target.dataset.showWhen; };
    select.addEventListener('change', update);
    update();
  });

  // Pre-fill the time zone from the browser while it is still the server default (UTC).
  document.querySelectorAll('input[data-detect-timezone]').forEach((input) => {
    if (input.value !== '' && input.value !== 'UTC') return;
    try {
      const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (zone) input.value = zone;
    } catch { /* keep the server default */ }
  });

  // Copy buttons next to read-only URL fields.
  document.querySelectorAll('.copy').forEach((button) => {
    button.addEventListener('click', async () => {
      const input = button.parentElement.querySelector('input');
      input.select();
      try {
        await navigator.clipboard.writeText(input.value);
        button.textContent = 'Copied';
      } catch {
        document.execCommand('copy');
        button.textContent = 'Copied';
      }
      setTimeout(() => { button.textContent = 'Copy'; }, 1500);
    });
  });

  // Confirm dangerous actions.
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  // Reload the preview image.
  document.querySelectorAll('.reload-preview').forEach((button) => {
    button.addEventListener('click', () => {
      const img = document.querySelector('img.preview');
      const url = new URL(img.src);
      url.searchParams.set('t', Date.now());
      img.src = url.toString();
    });
  });
});
