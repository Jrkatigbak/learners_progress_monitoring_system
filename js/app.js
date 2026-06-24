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
});
