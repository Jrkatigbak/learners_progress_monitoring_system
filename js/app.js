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

  if (window.credentialResetNotice) {
    // Show a clear sent/not-sent notification after the reset request redirects back.
    if (window.Swal) {
      Swal.fire({
        icon: window.credentialResetNotice.icon,
        title: window.credentialResetNotice.title,
        text: window.credentialResetNotice.text,
        confirmButtonColor: '#f58220'
      });
    } else {
      window.alert(window.credentialResetNotice.title + '\n\n' + window.credentialResetNotice.text);
    }
  }

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

  function updateClassLearnerPicker() {
    var keyword = ($('#classLearnerSearch').val() || '').toLowerCase().trim();
    var visibleCount = 0;
    var selectedCount = 0;

    $('.class-learner-picker-card').each(function () {
      var $card = $(this);
      var matchesKeyword = keyword === '' || ($card.attr('data-search') || '').indexOf(keyword) !== -1;

      $card.toggleClass('d-none', !matchesKeyword);

      if (matchesKeyword) {
        visibleCount++;
      }

      if ($card.find('input[type="checkbox"]').prop('checked')) {
        selectedCount++;
      }
    });

    $('#classLearnerNoResults').toggleClass('d-none', visibleCount > 0);
    $('#classLearnerSelectedCount').text(selectedCount + ' selected');
  }

  $('#classLearnerSearch').on('input', updateClassLearnerPicker);
  $(document).on('change', '.class-learner-picker-card input[type="checkbox"]', updateClassLearnerPicker);
  updateClassLearnerPicker();

  function updateClassTeacherPicker() {
    var keyword = ($('#classTeacherSearch').val() || '').toLowerCase().trim();
    var visibleCount = 0;
    var selectedCount = 0;

    $('.class-teacher-picker-card').each(function () {
      var $card = $(this);
      var matchesKeyword = keyword === '' || ($card.attr('data-search') || '').indexOf(keyword) !== -1;

      $card.toggleClass('d-none', !matchesKeyword);

      if (matchesKeyword) {
        visibleCount++;
      }

      if ($card.find('input[type="checkbox"]').prop('checked')) {
        selectedCount++;
      }
    });

    $('#classTeacherNoResults').toggleClass('d-none', visibleCount > 0);
    $('#classTeacherSelectedCount').text(selectedCount + ' selected');
  }

  $('#classTeacherSearch').on('input', updateClassTeacherPicker);
  $(document).on('change', '.class-teacher-picker-card input[type="checkbox"]', updateClassTeacherPicker);
  updateClassTeacherPicker();

  function updateTopicSearch() {
    var keyword = ($('#topicSearchInput').val() || '').toLowerCase().trim();
    var visibleCount = 0;

    $('.topic-search-card').each(function () {
      var $card = $(this);
      var matchesKeyword = keyword === '' || ($card.attr('data-topic-search') || '').indexOf(keyword) !== -1;

      $card.toggleClass('d-none', !matchesKeyword);

      if (matchesKeyword) {
        visibleCount++;
      }
    });

    $('#topicSearchNoResults').toggleClass('d-none', visibleCount > 0);
  }

  $('#topicSearchInput').on('input', updateTopicSearch);
  updateTopicSearch();

  $(document).on('submit', '.credential-reset-form', function (event) {
    var $form = $(this);
    var message = $form.attr('data-confirm-message') || 'Reset and resend login credentials?';

    if (!window.confirm(message)) {
      event.preventDefault();
      return;
    }

    var $button = $form.find('button[type="submit"]').first();

    // Resetting credentials sends email through SMTP, so show progress until the page redirects.
    $button
      .prop('disabled', true)
      .addClass('is-loading')
      .attr('aria-label', 'Sending credentials')
      .html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span class="visually-hidden">Sending credentials</span>');
  });

  $(document).on('submit', '.enrollment-approve-form', function () {
    var $form = $(this);
    var $button = $form.find('button[type="submit"]').first();

    $button
      .prop('disabled', true)
      .addClass('is-loading')
      .html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Enrolling...');

    $('.enrollment-approve-form button[type="submit"]').not($button).prop('disabled', true);

    if (window.Swal) {
      Swal.fire({
        title: 'Enrolling learner',
        text: 'Creating the learner account and sending login credentials. Please wait...',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: function () {
          Swal.showLoading();
        }
      });
    }
  });

  var materialLinkIndex = 1;
  var selectedMaterialFiles = [];

  function formatMaterialFileSize(bytes) {
    var value = Number(bytes) || 0;
    var units = ['B', 'KB', 'MB', 'GB'];
    var unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
      value /= 1024;
      unitIndex++;
    }

    return (unitIndex === 0 ? value : value.toFixed(1)) + ' ' + units[unitIndex];
  }

  function materialFileKey(file) {
    return [file.name, file.size, file.lastModified].join('|');
  }

  function syncMaterialFileInput() {
    var input = document.getElementById('material_files');

    if (!input || !window.DataTransfer) {
      return;
    }

    // The browser replaces file inputs on every browse action, so keep our combined list in sync.
    var dataTransfer = new DataTransfer();
    selectedMaterialFiles.forEach(function (file) {
      dataTransfer.items.add(file);
    });
    input.files = dataTransfer.files;
  }

  function mergeMaterialFiles(files) {
    var existingKeys = selectedMaterialFiles.map(materialFileKey);

    Array.prototype.forEach.call(files || [], function (file) {
      if (existingKeys.indexOf(materialFileKey(file)) === -1) {
        selectedMaterialFiles.push(file);
        existingKeys.push(materialFileKey(file));
      }
    });

    syncMaterialFileInput();
    renderMaterialFiles();
  }

  function renderMaterialFiles() {
    var fileList = selectedMaterialFiles;
    var $panel = $('#materialFilePanel');
    var $list = $('#materialFileList');

    $('#materialFileCount').text(fileList.length + (fileList.length === 1 ? ' file selected' : ' files selected'));
    $panel.toggleClass('d-none', fileList.length === 0);
    $list.empty();

    fileList.forEach(function (file) {
      var extension = (file.name.split('.').pop() || '').toLowerCase();
      var iconClass = extension === 'pdf' ? 'fa-regular fa-file-pdf' : 'fa-regular fa-file-lines';
      var $row = $('<div class="material-file-row"></div>');

      // Show the exact selected file name and size before the form is submitted.
      $('<span class="material-file-icon"></span>')
        .toggleClass('is-pdf', extension === 'pdf')
        .append($('<i></i>').addClass(iconClass))
        .appendTo($row);
      $('<span class="material-file-name"></span>').text(file.name).appendTo($row);
      $('<span class="material-file-size"></span>').text(formatMaterialFileSize(file.size)).appendTo($row);
      $list.append($row);
    });
  }

  function setMaterialFiles(files) {
    mergeMaterialFiles(files);
  }

  function ensureMaterialLinkRemoveState() {
    var $rows = $('.material-link-row');
    $rows.find('.material-link-remove').prop('disabled', $rows.length === 1);
  }

  function addMaterialLinkRow() {
    var fieldId = 'material_url_' + materialLinkIndex++;
    var $row = $('<div class="material-link-row"></div>');

    $('<input type="url" class="form-control" name="material_urls[]" placeholder="https://youtube.com/... or https://drive.google.com/...">')
      .attr('id', fieldId)
      .appendTo($row);
    $('<button type="button" class="btn btn-outline-secondary material-link-remove" aria-label="Remove link"><i class="fa-solid fa-xmark"></i></button>')
      .appendTo($row);
    $('#materialLinkList').append($row);
    ensureMaterialLinkRemoveState();
    $('#' + fieldId).trigger('focus');
  }

  $('#materialDropZone').on('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      $('#material_files').trigger('click');
    }
  });

  $('#materialDropZone').on('dragenter dragover', function (event) {
    event.preventDefault();
    event.stopPropagation();
    $(this).addClass('is-dragover');
  });

  $('#materialDropZone').on('dragleave dragend drop', function (event) {
    event.preventDefault();
    event.stopPropagation();
    $(this).removeClass('is-dragover');
  });

  $('#materialDropZone').on('drop', function (event) {
    var droppedFiles = event.originalEvent.dataTransfer ? event.originalEvent.dataTransfer.files : null;
    setMaterialFiles(droppedFiles);
  });

  $('#material_files').on('change', function () {
    mergeMaterialFiles(this.files);
  });

  $('#addMaterialLink').on('click', addMaterialLinkRow);

  $(document).on('click', '.material-link-remove', function () {
    if ($('.material-link-row').length > 1) {
      $(this).closest('.material-link-row').remove();
      ensureMaterialLinkRemoveState();
    }
  });

  ensureMaterialLinkRemoveState();

  $(document).on('click', '.edit-material-button', function () {
    var $button = $(this);
    var externalUrl = $button.attr('data-external-url') || '';
    var $externalUrlInput = $('#edit_material_external_url');

    // Populate the edit modal from the clicked material card without reloading the page.
    $('#edit_material_id').val($button.attr('data-material-id') || '');
    $('#edit_material_title').val($button.attr('data-title') || '');
    $('#edit_material_description').val($button.attr('data-description') || '');
    $('#edit_material_folder_id').val($button.attr('data-topic-id') || '0');
    $externalUrlInput
      .val(externalUrl)
      .prop('disabled', externalUrl === '')
      .attr('placeholder', externalUrl === '' ? 'Uploaded files keep their saved file' : 'https://');
  });

  $(document).on('click', '.edit-topic-button', function () {
    var $button = $(this);

    // Keep topic renaming in a small modal so the topic card remains clickable for filtering.
    $('#edit_topic_id').val($button.attr('data-topic-id') || '');
    $('#edit_topic_name').val($button.attr('data-name') || '');
    $('#edit_topic_description').val($button.attr('data-description') || '');
    $('#edit_topic_return_tool').val($button.attr('data-return-tool') || 'materials');
    $('#edit_topic_existing_banner').val($button.attr('data-banner-image') || '');
    $('#edit_topic_banner_image').val('');
  });

  $(document).on('click', '.edit-task-button', function () {
    var $selectedTask = $('#task_id_filter option:selected');

    // Edit whichever grade item is currently selected in the Grades filter.
    $('#edit_task_id').val($selectedTask.val() || '');
    $('#edit_task_topic_id').val($selectedTask.attr('data-topic-id') || '');
    $('#edit_task_title').val($selectedTask.attr('data-title') || '');
    $('#edit_task_date').val($selectedTask.attr('data-task-date') || '');
    $('#edit_task_max_score').val($selectedTask.attr('data-max-score') || '100');
    $('#edit_task_passing_score').val($selectedTask.attr('data-passing-score') || '');
    $('#edit_task_description').val($selectedTask.attr('data-description') || '');
    validateGradeSettings($('#editTaskModal'));
  });

  function validateGradeSettings($scope) {
    var $maxInput = $scope.find('.grade-max-score-input');
    var $passingInput = $scope.find('.grade-passing-score-input');
    var $warning = $scope.find('.grade-setting-warning');
    var $submit = $scope.find('button[type="submit"]');
    var maxScore = parseFloat($maxInput.val() || '0');
    var passingScore = parseFloat($passingInput.val() || '0');
    var hasWarning = passingScore > 0 && maxScore > 0 && passingScore > maxScore;

    // Warn before submit so invalid passing grades do not wait for a server round trip.
    $warning.toggleClass('d-none', !hasWarning);
    $submit.prop('disabled', hasWarning);
  }

  $(document).on('input', '.grade-max-score-input, .grade-passing-score-input', function () {
    validateGradeSettings($(this).closest('.modal'));
  });

  $('#taskModal, #editTaskModal').on('shown.bs.modal', function () {
    validateGradeSettings($(this));
  });

  var gradeAutosaveTimers = {};

  function scheduleGradeRowAutosave($row, action) {
    var $scoreInput = $row.find('.grade-score-input');
    var $remarksInput = $row.find('.grade-other-remarks-input');
    var $status = $row.find('.grade-autosave-status');
    var learnerId = $remarksInput.attr('data-learner-id') || '';
    var timerKey = (action || 'grade-row') + '-' + (learnerId || $remarksInput.attr('name') || 'grade-row');

    window.clearTimeout(gradeAutosaveTimers[timerKey]);
    $status.removeClass('text-success text-danger').addClass('text-secondary').text('Saving...');

    gradeAutosaveTimers[timerKey] = window.setTimeout(function () {
      $.ajax({
        method: 'POST',
        url: window.location.href,
        dataType: 'json',
        data: {
          action: action,
          class_id: $('input[name="class_id"]').first().val() || '',
          task_id: $remarksInput.attr('data-task-id') || $('input[name="task_id"]').val() || '',
          learner_id: learnerId,
          score: $scoreInput.val() || '',
          result_remark: $row.find('.grade-result-input').val() || '',
          other_remarks: $remarksInput.val() || ''
        }
      }).done(function (response) {
        if (response && response.ok) {
          if (response.grade_id) {
            $remarksInput.attr('data-grade-id', response.grade_id);
          }
          $status.removeClass('text-secondary text-danger').addClass('text-success').text('Saved');
          return;
        }

        $status.removeClass('text-secondary text-success').addClass('text-danger').text((response && response.message) || 'Not saved');
      }).fail(function () {
        $status.removeClass('text-secondary text-success').addClass('text-danger').text('Not saved');
      });
    }, 500);
  }

  $(document).on('input', '.grade-score-input', function () {
    var $scoreInput = $(this);
    var $row = $scoreInput.closest('tr');
    var $resultInput = $row.find('.grade-result-input');
    var $statusBadge = $row.find('.grade-status-badge');
    var scoreValue = $scoreInput.val();
    var passingScore = parseFloat($scoreInput.attr('data-passing-score') || '0');
    var score = parseFloat(scoreValue || '0');
    var result = '';

    // While typing grades, automatically mark the learner result from the grade item's passing score.
    if (!scoreValue || passingScore <= 0 || !$resultInput.length) {
      result = '';
      $resultInput.val(result);
      $statusBadge
        .removeClass('text-bg-success text-bg-danger')
        .addClass('text-bg-secondary')
        .text('No result');
      if (scoreValue) {
        scheduleGradeRowAutosave($row, 'ajax_save_grade_score');
      }
      return;
    }

    result = score >= passingScore ? 'Pass' : 'Failed';
    $resultInput.val(result);
    $statusBadge
      .removeClass('text-bg-success text-bg-danger text-bg-secondary')
      .addClass(result === 'Pass' ? 'text-bg-success' : 'text-bg-danger')
      .text(result);

    scheduleGradeRowAutosave($row, 'ajax_save_grade_score');
  });

  $(document).on('input', '.grade-other-remarks-input', function () {
    scheduleGradeRowAutosave($(this).closest('tr'), 'ajax_save_grade_other_remarks');
  });

  $(document).on('click', '.edit-quiz-button', function () {
    var $button = $(this);

    // Edit quiz settings without touching saved questions or learner attempts.
    $('#edit_quiz_id').val($button.attr('data-quiz-id') || '');
    $('#edit_quiz_title').val($button.attr('data-title') || '');
    $('#edit_quiz_description').val($button.attr('data-description') || '');
    $('#edit_quiz_topic_id').val($button.attr('data-topic-id') || '0');
    $('#edit_timer_minutes').val($button.attr('data-timer-minutes') || '10');
    $('#edit_quiz_status').val($button.attr('data-status') || 'Active');

    try {
      window.renderEditQuizQuestions(JSON.parse($button.attr('data-questions') || '[]'));
    } catch (error) {
      window.renderEditQuizQuestions([]);
    }
  });

  $(document).on('click', '.add-topic-button', function () {
    // Return to the module where the user opened Add Topic.
    $('#topic_return_tool').val($(this).attr('data-return-tool') || 'materials');
  });

  $(document).on('dragstart', '.material-draggable-card', function (event) {
    var materialId = $(this).attr('data-material-id') || '';

    // Store only the material id; the server validates class and topic ownership before moving it.
    event.originalEvent.dataTransfer.effectAllowed = 'move';
    event.originalEvent.dataTransfer.setData('text/plain', materialId);
    $(this).addClass('is-dragging');
  });

  $(document).on('dragend', '.material-draggable-card', function () {
    $('.material-draggable-card').removeClass('is-dragging');
    $('.material-topic-dropzone').removeClass('is-dragover');
  });

  $(document).on('dragover', '.material-topic-dropzone', function (event) {
    event.preventDefault();
    event.originalEvent.dataTransfer.dropEffect = 'move';
    $(this).addClass('is-dragover');
  });

  $(document).on('dragleave', '.material-topic-dropzone', function () {
    $(this).removeClass('is-dragover');
  });

  $(document).on('drop', '.material-topic-dropzone', function (event) {
    event.preventDefault();

    var $topicCard = $(this);
    var materialId = event.originalEvent.dataTransfer.getData('text/plain') || '';
    var topicId = $topicCard.attr('data-topic-id') || '';
    var classId = $('input[name="class_id"]').first().val() || new URLSearchParams(window.location.search).get('class_id') || '';

    if (materialId === '' || topicId === '' || classId === '') {
      return;
    }

    $topicCard.addClass('is-saving');

    $.ajax({
      method: 'POST',
      url: 'class_workspace.php',
      dataType: 'json',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      },
      data: {
        action: 'move_material_topic',
        class_id: classId,
        tool: 'materials',
        material_id: materialId,
        folder_id: topicId
      }
    }).done(function () {
      window.location.reload();
    }).fail(function (xhr) {
      var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Material could not be moved.';

      if (window.Swal) {
        Swal.fire('Move failed', message, 'error');
      } else {
        window.alert(message);
      }
    }).always(function () {
      $topicCard.removeClass('is-saving is-dragover');
      $('.material-draggable-card').removeClass('is-dragging');
    });
  });

  $(document).on('click', '.copy-enrollment-link', function () {
    var link = $(this).attr('data-link') || '';

    if (link === '') {
      return;
    }

    navigator.clipboard.writeText(link).then(function () {
      if (window.Swal) {
        Swal.fire('Copied', 'Enrollment link copied to clipboard.', 'success');
      }
    }).catch(function () {
      window.prompt('Copy enrollment link', link);
    });
  });
});
