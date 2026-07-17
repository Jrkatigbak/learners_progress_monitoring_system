-- Beginner Laravel and Git quizzes.
-- Change @class_id before running on live if the target course/class uses a different id.
-- This script inserts seed data only. No ALTER/table change is required.

START TRANSACTION;

SET @class_id := 11;
SET @created_by_user_id := NULL;

UPDATE class_quizzes
SET deleted_at = NOW(), status = 'Inactive'
WHERE class_id = @class_id
  AND deleted_at IS NULL
  AND title IN ('Beginner Laravel Commands and Setup', 'Beginner Git Basics');

SET @laravel_folder_id := (
  SELECT id
  FROM class_material_folders
  WHERE class_id = @class_id
    AND name = 'Laravel Training'
    AND deleted_at IS NULL
  LIMIT 1
);

INSERT INTO class_material_folders (class_id, name, description, sort_order, created_by_user_id)
SELECT @class_id, 'Laravel Training', 'Beginner Laravel commands, setup, and core concepts.', 7, @created_by_user_id
WHERE @laravel_folder_id IS NULL;

SET @laravel_folder_id := COALESCE(@laravel_folder_id, LAST_INSERT_ID());

SET @git_folder_id := (
  SELECT id
  FROM class_material_folders
  WHERE class_id = @class_id
    AND name = 'GITHUB Training'
    AND deleted_at IS NULL
  LIMIT 1
);

INSERT INTO class_material_folders (class_id, name, description, sort_order, created_by_user_id)
SELECT @class_id, 'GITHUB Training', 'Beginner Git and GitHub workflow commands.', 8, @created_by_user_id
WHERE @git_folder_id IS NULL;

SET @git_folder_id := COALESCE(@git_folder_id, LAST_INSERT_ID());

INSERT INTO class_quizzes (class_id, folder_id, title, description, timer_minutes, status, created_by_user_id)
VALUES (
  @class_id,
  @laravel_folder_id,
  'Beginner Laravel Commands and Setup',
  'Multiple-choice quiz covering basic Laravel Artisan, Composer, migration, route, and project setup commands.',
  30,
  'Active',
  @created_by_user_id
);
SET @quiz_id := LAST_INSERT_ID();

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to create a new Laravel project using Composer?', 1);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'composer create-project laravel/laravel project-name', 1, 1),
(@question_id, 'php artisan make:project project-name', 0, 2),
(@question_id, 'laravel new-controller project-name', 0, 3),
(@question_id, 'composer install laravel/project-name', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to start Laravel''s local development server?', 2);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan serve', 1, 1),
(@question_id, 'php artisan start', 0, 2),
(@question_id, 'npm run serve', 0, 3),
(@question_id, 'composer serve', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What is the default local URL after running php artisan serve?', 3);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'http://127.0.0.1:8000', 1, 1),
(@question_id, 'http://localhost/phpmyadmin', 0, 2),
(@question_id, 'http://127.0.0.1:3000', 0, 3),
(@question_id, 'http://localhost:8080', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to check the installed Laravel version?', 4);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan --version', 1, 1),
(@question_id, 'php artisan version:list', 0, 2),
(@question_id, 'composer laravel --version', 0, 3),
(@question_id, 'php artisan check:version', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to display the list of available Artisan commands?', 5);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan list', 1, 1),
(@question_id, 'php artisan commands', 0, 2),
(@question_id, 'php artisan show', 0, 3),
(@question_id, 'composer artisan list', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to create a new controller named StudentController?', 6);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan make:controller StudentController', 1, 1),
(@question_id, 'php artisan create:controller StudentController', 0, 2),
(@question_id, 'php artisan make:model StudentController', 0, 3),
(@question_id, 'composer make:controller StudentController', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to create a new model named Student?', 7);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan make:model Student', 1, 1),
(@question_id, 'php artisan create:model Student', 0, 2),
(@question_id, 'php artisan make:controller Student', 0, 3),
(@question_id, 'composer make:model Student', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command creates a model together with a migration file?', 8);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan make:model Student -m', 1, 1),
(@question_id, 'php artisan make:migration Student -m', 0, 2),
(@question_id, 'php artisan model:migration Student', 0, 3),
(@question_id, 'php artisan make:model Student --controller', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to create a new migration file for a students table?', 9);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan make:migration create_students_table', 1, 1),
(@question_id, 'php artisan migrate create_students_table', 0, 2),
(@question_id, 'php artisan make:table students', 0, 3),
(@question_id, 'composer make:migration students', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to run all pending database migrations?', 10);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan migrate', 1, 1),
(@question_id, 'php artisan migrate:run', 0, 2),
(@question_id, 'php artisan db:seed', 0, 3),
(@question_id, 'php artisan migrate:fresh', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to undo the most recent database migration batch?', 11);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan migrate:rollback', 1, 1),
(@question_id, 'php artisan migrate:undo', 0, 2),
(@question_id, 'php artisan migrate:reset-last', 0, 3),
(@question_id, 'php artisan db:rollback', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to delete all database tables and run the migrations again?', 12);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan migrate:fresh', 1, 1),
(@question_id, 'php artisan migrate:rollback', 0, 2),
(@question_id, 'php artisan db:seed', 0, 3),
(@question_id, 'php artisan migrate:refresh-one', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command creates a new seeder named StudentSeeder?', 13);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan make:seeder StudentSeeder', 1, 1),
(@question_id, 'php artisan migrate:fresh --seed', 0, 2),
(@question_id, 'php artisan db:seed StudentSeeder', 0, 3),
(@question_id, 'php artisan make:model StudentSeeder', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command runs all registered database seeders?', 14);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan db:seed', 1, 1),
(@question_id, 'php artisan make:seeder', 0, 2),
(@question_id, 'php artisan migrate', 0, 3),
(@question_id, 'php artisan route:list', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to clear Laravel''s application cache?', 15);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan cache:clear', 1, 1),
(@question_id, 'php artisan route:clear', 0, 2),
(@question_id, 'php artisan config:show', 0, 3),
(@question_id, 'composer cache:clear-laravel', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to clear the route cache?', 16);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan route:clear', 1, 1),
(@question_id, 'php artisan cache:clear', 0, 2),
(@question_id, 'php artisan route:list', 0, 3),
(@question_id, 'php artisan view:clear-routes', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to display all registered routes in a Laravel project?', 17);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan route:list', 1, 1),
(@question_id, 'php artisan route:clear', 0, 2),
(@question_id, 'php artisan list:routes', 0, 3),
(@question_id, 'composer routes', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command creates a resource controller?', 18);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan make:controller StudentController --resource', 1, 1),
(@question_id, 'php artisan make:resource StudentController', 0, 2),
(@question_id, 'php artisan controller:resource StudentController', 0, 3),
(@question_id, 'php artisan make:controller StudentController -m', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What is the purpose of the .env file in Laravel?', 19);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'The .env file stores the application''s environment configuration.', 1, 1),
(@question_id, 'The .env file stores controller classes.', 0, 2),
(@question_id, 'The .env file stores Blade templates.', 0, 3),
(@question_id, 'The .env file stores route definitions only.', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What file contains the web routes of a Laravel application?', 20);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'routes/web.php', 1, 1),
(@question_id, 'app/Http/Controllers/web.php', 0, 2),
(@question_id, 'config/routes.php', 0, 3),
(@question_id, 'resources/views/web.php', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'A developer receives a Laravel project from GitHub without a vendor folder. What command installs the project dependencies?', 21);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'composer install', 1, 1),
(@question_id, 'composer create-project', 0, 2),
(@question_id, 'php artisan serve', 0, 3),
(@question_id, 'npm run migrate', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What file should the developer copy to create their local environment configuration?', 22);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, '.env', 1, 1),
(@question_id, 'routes/web.php', 0, 2),
(@question_id, 'composer.json', 0, 3),
(@question_id, 'artisan', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command should the developer run to generate the Laravel application key?', 23);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'php artisan key:generate', 1, 1),
(@question_id, 'php artisan app:key', 0, 2),
(@question_id, 'composer key:generate', 0, 3),
(@question_id, 'php artisan cache:clear', 0, 4);

INSERT INTO class_quizzes (class_id, folder_id, title, description, timer_minutes, status, created_by_user_id)
VALUES (
  @class_id,
  @git_folder_id,
  'Beginner Git Basics',
  'Multiple-choice quiz covering basic Git commands for initializing, staging, committing, branching, pulling, pushing, cloning, and merging.',
  20,
  'Active',
  @created_by_user_id
);
SET @quiz_id := LAST_INSERT_ID();

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to initialize Git inside a project folder?', 1);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git init', 1, 1),
(@question_id, 'git start', 0, 2),
(@question_id, 'git create', 0, 3),
(@question_id, 'git clone', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command displays the current status of the Git repository?', 2);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git status', 1, 1),
(@question_id, 'git log', 0, 2),
(@question_id, 'git branch', 0, 3),
(@question_id, 'git show-status', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command adds all modified and new files to the staging area?', 3);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git add .', 1, 1),
(@question_id, 'git commit .', 0, 2),
(@question_id, 'git push .', 0, 3),
(@question_id, 'git stage --none', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command adds only one specific file to the staging area?', 4);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git add filename.php', 1, 1),
(@question_id, 'git status filename.php', 0, 2),
(@question_id, 'git push filename.php', 0, 3),
(@question_id, 'git commit filename.php', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command creates a commit with the message Add student module?', 5);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git commit -m "Add student module"', 1, 1),
(@question_id, 'git add app/Http/Controllers/StudentController.php', 0, 2),
(@question_id, 'git log "Add student module"', 0, 3),
(@question_id, 'git branch "Add student module"', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command displays the Git commit history?', 6);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git log', 1, 1),
(@question_id, 'git commit -m "Add student module"', 0, 2),
(@question_id, 'git status', 0, 3),
(@question_id, 'git branch', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command displays all local branches?', 7);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git branch', 1, 1),
(@question_id, 'git log', 0, 2),
(@question_id, 'git pull', 0, 3),
(@question_id, 'git init', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command creates a new branch named student-feature?', 8);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git branch student-feature', 1, 1),
(@question_id, 'git branch', 0, 2),
(@question_id, 'git checkout student-feature', 0, 3),
(@question_id, 'git merge student-feature', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command switches to the student-feature branch?', 9);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git checkout student-feature', 1, 1),
(@question_id, 'git branch student-feature', 0, 2),
(@question_id, 'git merge student-feature', 0, 3),
(@question_id, 'git clone student-feature', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What single command creates and switches to a new branch named student-feature?', 10);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git switch -c student-feature', 1, 1),
(@question_id, 'git branch student-feature', 0, 2),
(@question_id, 'git checkout student-feature', 0, 3),
(@question_id, 'git pull student-feature', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command downloads and merges changes from a remote repository?', 11);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git pull', 1, 1),
(@question_id, 'git push', 0, 2),
(@question_id, 'git clone', 0, 3),
(@question_id, 'git merge', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command uploads local commits to the remote repository?', 12);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git push', 1, 1),
(@question_id, 'git pull', 0, 2),
(@question_id, 'git init', 0, 3),
(@question_id, 'git status', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to copy an existing GitHub repository to a computer?', 13);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git clone <repository-url>', 1, 1),
(@question_id, 'git init <repository-url>', 0, 2),
(@question_id, 'git pull <repository-url>', 0, 3),
(@question_id, 'git copy <repository-url>', 0, 4);

INSERT INTO quiz_questions (quiz_id, question_text, position) VALUES (@quiz_id, 'What command is used to merge the branch student-feature into the current branch?', 14);
SET @question_id := LAST_INSERT_ID();
INSERT INTO quiz_choices (question_id, choice_text, is_correct, position) VALUES
(@question_id, 'git merge student-feature', 1, 1),
(@question_id, 'git checkout student-feature', 0, 2),
(@question_id, 'git branch student-feature', 0, 3),
(@question_id, 'git push student-feature', 0, 4);

COMMIT;
