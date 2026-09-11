<?php

namespace MathiasGrimm\GlimpseCli\Commands;

use LaravelZero\Framework\Commands\Command;
use MathiasGrimm\GlimpseCli\Commands\Concerns\GuardsApiErrors;
use MathiasGrimm\GlimpseCli\Support\BaselineFile;
use MathiasGrimm\GlimpseCli\Support\IgnoreFile;
use MathiasGrimm\GlimpseCli\Support\Paths;
use MathiasGrimm\GlimpseCli\Support\ScaffoldFile;
use MathiasGrimm\GlimpsePhp\ApiException;

class InitCommand extends Command
{
    use GuardsApiErrors;

    protected $signature = 'init
        {--update-baseline : Seed the baseline by scanning the current directory (runs analyze . --update-baseline)}
        {--workflow : Add the check and optimize GitHub Actions workflow without prompting}
        {--workflow-mode= : Workflow to add without prompting: check or optimize (default)}
        {--force : Recreate .glimpseignore from its template even when it exists; select a workflow with --workflow or --workflow-mode to also recreate it}';

    protected $description = 'Set up the current directory for glimpse: a starter .glimpseignore, the baseline, and optionally a CI workflow';

    /**
     * Where the scaffolded GitHub Actions workflow lives, relative to
     * the project root.
     */
    public const WORKFLOW_PATH = '.github/workflows/glimpse.yml';

    /**
     * The starter .glimpseignore. One static template, no project-type
     * detection: the patterns are harmless on projects where they match
     * nothing.
     */
    private const IGNORE_TEMPLATE = <<<'GITIGNORE'
        # Created by glimpse init. Paths listed here are excluded from glimpse
        # scans (check, analyze), using gitignore syntax relative to this file.

        # Dependencies you do not control
        vendor/
        node_modules/

        # Generated and built assets
        public/build/
        dist/

        # Runtime state and user uploads (Laravel)
        storage/

        # Favicons and touch icons: tiny and format-constrained, not worth optimizing
        favicon.ico
        favicon*.png
        apple-touch-icon*.png

        GITIGNORE;

    /**
     * The scaffolded GitHub Actions workflow. A nowdoc, because the
     * YAML carries ${{ ... }} expressions a heredoc would interpolate.
     * The continuous-integration page of the docs (glimpseimg.com/docs)
     * shows this template as the copy-by-hand alternative; update it
     * when the template changes. The glimpseimg.com repo runs a
     * docs-drift workflow that compares the two and fails on mismatch.
     *
     * The install goes through Composer so every CI run is counted as a
     * Packagist install. The package declares no runtime dependencies
     * (the bin is the committed phar), so the install only downloads the
     * package itself; no cache step is worth the extra YAML.
     *
     * The GLIMPSE_TOKEN secret is optional. When the env var is empty
     * (fork pull requests never receive repository secrets, and a fresh
     * repository may not have set it yet), the CLI falls back to its
     * built-in public token, which can only call the analyze endpoint
     * and shares rate limits per runner IP. A repository's own secret
     * gives higher limits and usage attribution.
     */
    public const WORKFLOW_TEMPLATE = <<<'YAML'
        name: Glimpse

        on:
          push:
            branches: [main]
          pull_request:

        permissions:
          contents: read

        jobs:
          check-images:
            runs-on: ubuntu-latest
            env:
              # Optional. Without it (for example on fork pull requests, which
              # never receive secrets) the CLI uses its built-in public token,
              # which only allows check and analyze and shares rate limits.
              GLIMPSE_TOKEN: ${{ secrets.GLIMPSE_TOKEN }}
            steps:
              - uses: actions/checkout@v6
              - name: Install glimpse
                run: |
                  composer global require --no-interaction --no-progress mathiasgrimm/glimpse-cli
                  composer global config bin-dir --absolute --quiet >> "$GITHUB_PATH"
              - name: Check images
                run: glimpse check .

        YAML;

    /**
     * Complete post-merge workflow, also published in the automatic optimization docs.
     */
    public const OPTIMIZE_TEMPLATE = <<<'YAML'
        name: Glimpse automatic optimization

        on:
          pull_request_target:
            types: [closed]

        permissions:
          contents: write
          pull-requests: write

        concurrency:
          group: glimpse-optimize-${{ github.event.pull_request.base.ref }}
          cancel-in-progress: false

        jobs:
          optimize-images:
            if: github.event.pull_request.merged == true
            runs-on: ubuntu-24.04
            timeout-minutes: 30
            steps:
              - name: Install glimpse
                working-directory: ${{ runner.temp }}
                env:
                  COMPOSER_HOME: ${{ runner.temp }}/glimpse-composer
                run: |
                  composer global require --no-interaction --no-progress --no-plugins --no-scripts mathiasgrimm/glimpse-cli:1.4.0
                  composer global config bin-dir --absolute --quiet >> "$GITHUB_PATH"

              - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
                with:
                  ref: refs/heads/${{ github.event.pull_request.base.ref }}
                  persist-credentials: false

              - name: Check and optimize reported images
                id: images
                env:
                  GLIMPSE_TOKEN: ${{ secrets.GLIMPSE_TOKEN }}
                  BASE_BRANCH: ${{ github.event.pull_request.base.ref }}
                run: |
                  php <<'PHP'
                  <?php
                  function run(array $arguments): array
                  {
                      $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
                      if (!is_resource($process)) {
                          throw new RuntimeException('Could not start command.');
                      }
                      $output = stream_get_contents($pipes[1]);
                      fclose($pipes[1]);
                      return [proc_close($process), $output];
                  }

                  function command(array $arguments): string
                  {
                      [$status, $output] = run($arguments);
                      if ($status !== 0) {
                          throw new RuntimeException('Command failed: '.$arguments[0]);
                      }
                      return $output;
                  }

                  function readJson(string $json): array
                  {
                      $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                      if (!is_array($value)) {
                          throw new RuntimeException('Expected a JSON object.');
                      }
                      return $value;
                  }

                  function regularPath(string $file): string
                  {
                      $root = getcwd();
                      if ($file === '' || str_contains($file, "\0") || realpath($file) !== $root.'/'.$file || !is_file($file) || is_link($file)) {
                          throw new RuntimeException('Expected a regular file inside the repository: '.json_encode($file));
                      }
                      return './'.$file;
                  }

                  function imageDetails(string $path): array
                  {
                      $details = getimagesize($path);
                      if ($details === false) {
                          throw new RuntimeException('Could not read image dimensions and format.');
                      }
                      return [$details[0], $details[1], $details['mime']];
                  }

                  function finish(string $body): void
                  {
                      $branch = 'automation/glimpse-'.hash('sha256', getenv('BASE_BRANCH'));
                      foreach ([
                          getenv('RUNNER_TEMP').'/glimpse-pr.md' => $body,
                          getenv('GITHUB_STEP_SUMMARY') => $body,
                          getenv('GITHUB_OUTPUT') => "checked=true\nbranch=".$branch."\n",
                      ] as $path => $content) {
                          if (file_put_contents($path, $content, FILE_APPEND) === false) {
                              throw new RuntimeException('Could not write workflow results.');
                          }
                      }
                  }

                  if (trim(getenv('GLIMPSE_TOKEN') ?: '') === '') {
                      throw new RuntimeException('Set a private GLIMPSE_TOKEN repository secret.');
                  }
                  $baselinePath = '.glimpse-baseline.json';
                  if (is_link($baselinePath) || (file_exists($baselinePath) && !is_file($baselinePath))) {
                      throw new RuntimeException('The baseline must be a regular file.');
                  }
                  [$status, $json] = run(['glimpse', 'check', '.', '--json']);
                  $report = readJson($json);
                  if (!in_array($status, [0, 1], true) || !isset($report['files'], $report['failed'], $report['needs_optimization'])
                      || !is_array($report['files']) || !array_is_list($report['files']) || $report['failed'] !== []
                      || $report['needs_optimization'] !== count($report['files'])
                      || $status !== ($report['files'] === [] ? 0 : 1)) {
                      throw new RuntimeException('Image check failed or returned an invalid report.');
                  }
                  if ($report['files'] === []) {
                      finish("No images currently need optimization.\n");
                      exit(0);
                  }

                  $tracked = array_flip(explode("\0", command(['git', 'ls-files', '-z'])));
                  $files = [];
                  foreach ($report['files'] as $row) {
                      $file = $row['file'] ?? null;
                      if (!is_string($file) || !isset($tracked[$file]) || isset($files[$file])) {
                          throw new RuntimeException('The report must contain unique tracked image paths.');
                      }
                      $files[$file] = regularPath($file);
                  }
                  if (!file_exists($baselinePath)) {
                      if (file_put_contents($baselinePath, '{"files":{}}'.PHP_EOL) === false) {
                          throw new RuntimeException('Could not create the baseline.');
                      }
                  }

                  $body = "Optimize images at quality 85, keeping their formats, dimensions, and filenames.\n\n";
                  $body .= "Quality may change and metadata may be removed. Successful results are recorded in the baseline, including zero-saving results.\n\n";
                  $body .= "| Image | Before | After | Saved |\n| --- | ---: | ---: | ---: |\n";
                  foreach ($files as $file => $path) {
                      $before = strlen(file_get_contents($path));
                      $details = imageDetails($path);
                      command(['glimpse', 'optimize', $path, '--quality=85', '--output='.$path, '--force', '--json']);
                      clearstatcache();
                      regularPath($file);
                      $after = strlen(file_get_contents($path));
                      if ($after > $before || $after === 0 || imageDetails($path) !== $details) {
                          throw new RuntimeException('Optimization changed the format or dimensions, or increased the size.');
                      }
                      $baseline = readJson(file_get_contents($baselinePath));
                      $entry = $baseline['files'][$file] ?? null;
                      if (!is_array($entry) || ($entry['via'] ?? null) !== 'optimize'
                          || ($entry['size'] ?? null) !== $after || ($entry['xxh128'] ?? null) !== hash_file('xxh128', $path)) {
                          throw new RuntimeException('The optimized image was not recorded in the baseline.');
                      }
                      $label = htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                      $label = str_replace(["|", "\r", "\n"], ['&#124;', '&#13;', '&#10;'], $label);
                      $body .= '| <code>'.$label.'</code> | '.$before.' | '.$after.' | '.($before - $after)." |\n";
                  }

                  // Stage exact paths. The PR action adds only the baseline and keeps these staged images.
                  foreach ($files as $path) {
                      command(['git', '--literal-pathspecs', 'add', '--', $path]);
                  }
                  command(['git', '--literal-pathspecs', 'add', '--force', '--', $baselinePath]);
                  finish($body);
                  PHP

              - name: Open or update the optimization PR
                if: steps.images.outputs.checked == 'true'
                uses: peter-evans/create-pull-request@5f6978faf089d4d20b00c7766989d076bb2fc7f1 # v8
                with:
                  token: ${{ secrets.GITHUB_TOKEN }}
                  base: ${{ github.event.pull_request.base.ref }}
                  branch: ${{ steps.images.outputs.branch }}
                  title: Optimize images
                  commit-message: Optimize images
                  body-path: ${{ runner.temp }}/glimpse-pr.md
                  add-paths: .glimpse-baseline.json
                  delete-branch: true
        YAML;

    /**
     * Whether this run wrote the workflow file (created or recreated),
     * and whether it found and kept an existing one. Together with
     * "neither" they pick the next-steps variant.
     */
    private ?string $workflowWritten = null;

    private bool $workflowKept = false;

    public function handle(): int
    {
        return $this->runGuarded(function () {
            // The console application reuses the resolved command instance,
            // so the flags must not carry over from an earlier in-process run.
            $this->workflowWritten = null;
            $this->workflowKept = false;

            $mode = $this->requestedWorkflowMode();
            $root = Paths::root();

            // Order is load-bearing: the ignore file must exist before any
            // seed scan, because ImageFinder honors it; otherwise vendor/
            // images get recorded into the seeded baseline.
            $this->writeIgnoreFile($root);

            $exitCode = $this->setUpBaseline($root);

            $this->setUpWorkflow($root, $mode);

            // The empty-baseline warning and the seed hint key on what is
            // actually on disk after the run, not on what this run did: a
            // baseline kept from an earlier run may itself be empty, and a
            // failed refresh leaves a populated baseline intact.
            $this->printNextSteps(baselineEmpty: BaselineFile::load($root)->count() === 0);

            return $exitCode;
        });
    }

    private function writeIgnoreFile(string $root): void
    {
        $path = $root.'/'.IgnoreFile::FILENAME;
        $exists = is_file($path);

        if ($exists && ! $this->option('force')) {
            $this->line(IgnoreFile::FILENAME.' already exists, kept (use --force to recreate it from the starter template).');

            return;
        }

        ScaffoldFile::write($root, IgnoreFile::FILENAME, self::IGNORE_TEMPLATE, replace: $exists);

        $this->info($exists
            ? 'Recreated '.IgnoreFile::FILENAME.' from the starter template.'
            : 'Created '.IgnoreFile::FILENAME.'.');
    }

    /**
     * Create or seed the baseline. Seeding is opt-in: the --update-baseline
     * flag, or an interactive confirm defaulting to No, so a plain init
     * works with zero prerequisites (no token, no network). confirm()
     * returns the default when the input is non-interactive (-n) or stdin
     * is at end-of-file, the usual CI shape, so those runs fall through to
     * the empty baseline with zero API calls. Piped input that carries an
     * answer is read as that answer.
     *
     * Seeding accepts existing bytes rather than optimizing them. Keep the
     * prompt explicit because both workflow modes skip matching entries.
     */
    private function setUpBaseline(string $root): int
    {
        $path = $root.'/'.BaselineFile::FILENAME;
        $exists = is_file($path);

        $seed = (bool) $this->option('update-baseline');

        if (! $seed && ! $exists) {
            $seed = $this->confirm('Accept current images unchanged? Check and automatic optimization will skip them until they change (runs analyze . --update-baseline).', false);
        }

        if ($seed) {
            return $this->seedBaseline($path);
        }

        if ($exists) {
            $this->line(BaselineFile::FILENAME.' already exists, kept.');

            return self::SUCCESS;
        }

        BaselineFile::load($root)->save($root);
        $this->info('Created '.BaselineFile::FILENAME.' (empty).');

        return self::SUCCESS;
    }

    /**
     * Seed by delegating to analyze, reusing its locking, hashing, pruning,
     * and reporting; its exit code is passed through. A failed seed that
     * aborted before the baseline was saved still leaves an empty baseline
     * behind, so the project ends up scaffolded either way, but the run
     * fails because a requested seed did not happen.
     */
    private function seedBaseline(string $path): int
    {
        $exitCode = $this->call('analyze', ['input' => '.', '--update-baseline' => true]);

        if ($exitCode === self::SUCCESS) {
            return self::SUCCESS;
        }

        if (! is_file($path)) {
            BaselineFile::load(dirname($path))->save(dirname($path));
            $this->warn('The scan failed; wrote an empty '.BaselineFile::FILENAME.' instead. After fixing the problem, run: glimpse analyze . --update-baseline');
        }

        return self::FAILURE;
    }

    /**
     * Validate before scaffolding or seeding. An explicit mode also requests
     * the workflow, and overrides the default implied by --workflow.
     */
    private function requestedWorkflowMode(): ?string
    {
        $mode = $this->option('workflow-mode');

        if (($mode !== null || $this->input->hasParameterOption('--workflow-mode', true))
            && ! in_array($mode, ['check', 'optimize'], true)) {
            throw new ApiException('--workflow-mode must be check or optimize.');
        }

        return $mode ?? ($this->option('workflow') ? 'optimize' : null);
    }

    private function setUpWorkflow(string $root, ?string $mode): void
    {
        $path = $root.'/'.self::WORKFLOW_PATH;
        $exists = is_file($path);

        if ($exists && ($mode === null || ! $this->option('force'))) {
            $this->line(self::WORKFLOW_PATH.' already exists, kept (use --workflow-mode=check or --workflow-mode=optimize with --force to replace it).');
            $this->workflowKept = true;

            return;
        }

        if ($mode === null && $this->isGitRoot($root) && $this->input->isInteractive()) {
            $this->line('Check and optimize: after a PR merges, open an optimization PR. Requires a private GLIMPSE_TOKEN.');
            $this->line('Check only: check pull requests and pushes to main. The token is optional.');
            $choice = $this->choice('Which GitHub Actions workflow would you like?', [
                'Check and optimize',
                'Check only',
                'No workflow',
            ], 0);

            // Symfony makes input non-interactive when it reaches EOF and
            // returns the default answer. EOF must not opt into automation.
            if ($this->input->isInteractive()) {
                $mode = match ($choice) {
                    'Check and optimize' => 'optimize',
                    'Check only' => 'check',
                    default => null,
                };
            }
        }

        if ($mode === null) {
            return;
        }

        $template = $mode === 'check' ? self::WORKFLOW_TEMPLATE : self::OPTIMIZE_TEMPLATE;
        ScaffoldFile::write($root, self::WORKFLOW_PATH, $template, replace: $exists);
        $this->info($exists
            ? 'Recreated '.self::WORKFLOW_PATH.' from the starter template.'
            : 'Created '.self::WORKFLOW_PATH.'.');
        $this->workflowWritten = $mode;
    }

    /**
     * Whether the root is a git repository: .git is a directory in a
     * normal clone and a file in a worktree.
     */
    private function isGitRoot(string $root): bool
    {
        return file_exists($root.'/.git');
    }

    private function printNextSteps(bool $baselineEmpty): void
    {
        if ($baselineEmpty && $this->workflowWritten !== null) {
            $this->newLine();
            $this->warn($this->workflowWritten === 'check'
                ? 'The baseline is empty. The check-only workflow will fail on images above the threshold.'
                : 'The baseline is empty. After the next PR merges, automatic optimization will process all images reported by check and open a PR.');
        }

        $steps = ['Review '.IgnoreFile::FILENAME.' and tune the patterns for your project.'];

        if ($baselineEmpty) {
            $steps[] = 'To accept current images unchanged and skip them until they change: glimpse analyze . --update-baseline';
        }

        if ($this->workflowWritten !== null) {
            $steps[] = $this->workflowWritten === 'check'
                ? 'Optional: set the GLIMPSE_TOKEN secret for higher rate limits and usage attribution: gh secret set GLIMPSE_TOKEN'
                : 'Required: set a private GLIMPSE_TOKEN secret for optimization: gh secret set GLIMPSE_TOKEN';

            if ($this->workflowWritten === 'optimize') {
                $steps[] = 'Install the workflow on your default branch and allow GitHub Actions to create pull requests. Generated PR checks require approval.';
                $steps[] = 'Optimization uses quality 85, keeps formats and filenames, and may remove metadata. See https://glimpseimg.com/docs/cli/automatic-optimization';
            }
            $steps[] = 'Commit '.IgnoreFile::FILENAME.', '.BaselineFile::FILENAME.', and '.self::WORKFLOW_PATH.'.';
        } else {
            $steps[] = 'Commit '.IgnoreFile::FILENAME.' and '.BaselineFile::FILENAME.'.';

            $steps[] = $this->workflowKept
                ? 'Review '.self::WORKFLOW_PATH.'; a private GLIMPSE_TOKEN is required for optimization and optional for check only.'
                : 'Gate new images in CI: glimpse check .  (see https://glimpseimg.com/docs/cli/continuous-integration)';
        }

        $this->newLine();
        $this->line('Next steps:');

        foreach ($steps as $index => $step) {
            $this->line('  '.($index + 1).'. '.$step);
        }
    }
}
