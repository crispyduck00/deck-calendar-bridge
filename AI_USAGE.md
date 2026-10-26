# AI assistance and responsibility

Most of the initial application code, automated tests and documentation were created with substantial assistance from AI coding tools. The application's requirements and deployment decisions were supplied by its maintainer. This is an AI-assisted community project, not an official Nextcloud product.

AI assistance does not establish correctness, security or compatibility. The repository records executable tests, supported versions, known boundaries and per-commit CI results so that claims can be checked. A passing CI run is automated evidence; it is not an independent security audit, manual review by a qualified third party, or proof that every deployment will behave identically.

Maintainers remain responsible for accepting contributions, validating releases, responding to security reports and deciding whether to deploy the app. Users should review its administrator-delegated permission model and test the documented behavior in a disposable installation before adopting it.

## Contributions

- AI-assisted contributions are welcome. Disclose substantial assistance in the pull request and explain what was changed and how it was actually verified.
- Read the code and requirements you are changing. Do not submit unexplained generated rewrites or introduce a competing synchronization engine.
- Report the checks you ran, their actual results and any limits. Never invent test runs, client validation, human review or security assurances.
- Keep credentials, production configuration, personal card/calendar contents and private logs out of prompts, commits, examples, issues and test fixtures.
- Follow [CONTRIBUTING.md](CONTRIBUTING.md), [development principles](docs/DEVELOPMENT.md) and [security reporting](SECURITY.md). AI use does not replace those requirements.

The source is distributed under the license in [LICENSE.md](LICENSE.md). The AI disclosure describes how the project was produced; it is not a certification or a change to the license.
