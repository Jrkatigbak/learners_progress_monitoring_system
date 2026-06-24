$(function () {
  var storageKey = 'kiwi-dashboard-theme';

  function setTheme(theme) {
    var isDark = theme === 'dark';
    $('html').attr('data-theme', theme);
    $('#themeToggle')
      .attr('aria-pressed', isDark ? 'true' : 'false')
      .attr('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode')
      .find('i')
      .toggleClass('fa-moon', !isDark)
      .toggleClass('fa-sun', isDark);
    $('#themeToggle span').text(isDark ? 'Light' : 'Dark');
    localStorage.setItem(storageKey, theme);
  }

  setTheme(localStorage.getItem(storageKey) || 'light');

  $('#themeToggle').on('click', function () {
    var current = $('html').attr('data-theme') || 'light';
    setTheme(current === 'dark' ? 'light' : 'dark');
  });

  $('#loginForm').on('submit', function () {
    var $button = $('#loginButton');
    $button.prop('disabled', true);
    $button.find('.btn-label').text('Signing in...');
    $button.find('.spinner-border').removeClass('d-none');
  });

  $('#sidebarToggle').on('click', function () {
    $('.sidebar').toggleClass('open');
  });

  $(document).on('click', '.learner-photo-viewer-button', function () {
    var photo = $(this).attr('data-photo') || '';
    var name = $(this).attr('data-name') || 'Profile picture';

    // Load the clicked learner image into the Bootstrap preview modal.
    $('#learnerPhotoModalLabel').text(name);
    $('#learnerPhotoPreview')
      .attr('src', photo)
      .attr('alt', name + ' profile picture');
  });

  function filterGradeLearners() {
    var selectedClassId = $('#class_id').val() || '';
    var $learnerSelect = $('#learner_id');

    if (!$learnerSelect.length) {
      return;
    }

    $learnerSelect.find('option').each(function () {
      var $option = $(this);
      var optionClassId = $option.attr('data-class-id') || '';

      // Keep the placeholder visible and only show learners from the selected class.
      if ($option.val() === '' || selectedClassId === '' || optionClassId === selectedClassId) {
        $option.prop('hidden', false);
      } else {
        $option.prop('hidden', true);
      }
    });

    if ($learnerSelect.find('option:selected').prop('hidden')) {
      $learnerSelect.val('');
    }
  }

  $('#class_id').on('change', filterGradeLearners);
  filterGradeLearners();
});
