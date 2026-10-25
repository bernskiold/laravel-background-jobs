# Contributing

Contributions are welcome, and are accepted via pull requests. Please review these guidelines before submitting any pull requests.

## Process

1. Fork the project.
2. Create a new branch.
3. Code, test, commit and push.
4. Open a pull request detailing your changes.

## Guidelines

- Please ensure the coding style running `composer format`.
- Send a coherent commit history, making sure each individual commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](https://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Tests

Run the tests with:

```bash
composer test
```

## Code style

Format the code with [Pint](https://laravel.com/docs/pint):

```bash
composer format
```
