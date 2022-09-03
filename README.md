# git-stage-editor

When you stage files, git adds them to its object store in `.git/objects`. So when
you edit file in working directory, staged files stay unchanged until you add them
with `git add`. That's cool, until you want to perform some actions on staged files
themselves. You can always stash local changes, edit files, stage them, and get
changes back from the stash. But this way you might get some merge conflicts
when unstaging. This may be fine when you are editing files yourself, but what if
you want to edit them with scripts? For example, lint your code with git hooks.

The solution: edit files directly in the staging area. This library can help you
do this by retrieving file from stage and presenting temporary file with its contents
to you. All you have to do is pass a closure, that will modify this file, and it
will be modified and saved back to stage.

## Usage

1. Install `git-stage-editor` into your project
    ```shell
    composer require --dev xwillq/git-stage-editor
    ```
2. Instantiate `GitStagedFileEditor` with full path to your git repository
    ```php
    $editor = new \Xwillq\GitStageEditor\GitStagedFileEditor($git_root);
    ```
3. Run it with your callback
    ```php
    $editor->execute(function ($file, string $file_path) {
        exec("php vendor/bin/php-cs-fixer fix $file_path");
    });
    ```
