# CLIPHUNTER — PROJECT INSTRUCTIONS

## 0. ROLE

You are the primary senior software architect, senior PHP backend developer, senior frontend engineer, DevOps engineer, and security engineer for this project.

Your responsibility is to take ClipHunter from architecture through implementation, testing, GitHub integration, deployment, and verification.

Read and follow this document throughout the entire project.

Do not ignore these instructions unless explicitly instructed by the project owner.

---

# 1. PROJECT OVERVIEW

Project name: **ClipHunter**

ClipHunter is a minimalistic web service where a user pastes a video URL from a supported platform and receives the ability to download the video in a selected format and quality.

Core technologies:

- PHP 8.3+
- Composer
- HTML5
- CSS
- JavaScript
- yt-dlp
- ffmpeg
- Linux
- Nginx
- PHP-FPM

The project should be simple enough for a single developer to maintain while being architecturally sound enough for production use.

---

# 2. INITIAL STARTUP PROCEDURE

Before implementing anything:

1. Read this entire `CLAUDE.md`.
2. Inspect the current working directory.
3. Determine whether this is already the ClipHunter project.
4. Inspect the available development environment and installed tools.
5. Inspect PHP, Composer, Git, GitHub CLI, yt-dlp, ffmpeg, and SSH availability.
6. Inspect the current Git state and repository configuration.
7. Inspect `design-reference.png`.
8. Analyze the architecture and create the implementation plan.
9. Do not begin large-scale implementation until the architecture stage is complete.

Do not create the project in a random directory.

If the correct project directory cannot safely be determined, ask the project owner.

---

# 3. DESIGN REFERENCE

The primary visual reference is:

`design-reference.png`

Before implementing the frontend:

1. Open and inspect `design-reference.png`.
2. Analyze its layout, spacing, typography, colors, components, hierarchy, and visual language.
3. Use it as the primary visual direction for ClipHunter.
4. Do not copy the image literally.
5. Recreate its visual principles as an original, responsive, production-quality implementation.
6. Preserve the minimalist product/SaaS character.
7. Do not turn the website into a generic old-style video downloader.

Important design characteristics:

- minimalistic;
- modern;
- clean;
- lots of whitespace;
- strong visual hierarchy;
- obvious primary CTA;
- restrained palette;
- polished responsive behavior;
- no unnecessary visual noise.

---

# 4. CORE PRODUCT FLOW

The main user flow:

1. User opens ClipHunter.
2. User pastes a video URL.
3. User clicks the main CTA.
4. Backend validates the URL.
5. Backend analyzes it using yt-dlp.
6. Backend obtains metadata.
7. Frontend displays:
   - thumbnail;
   - title;
   - duration;
   - available formats;
   - available qualities;
   - file size when reliably available.
8. User selects a format/quality.
9. A download job is created.
10. yt-dlp downloads the media.
11. ffmpeg is used when stream merging or processing is required.
12. User receives the resulting file.
13. Temporary files are cleaned up.

The UX must remain extremely simple and understandable.

---

# 5. SUPPORTED PLATFORMS

Use yt-dlp as the downloader abstraction layer.

Potential sources include:

- YouTube;
- TikTok;
- VK;
- Instagram;
- Twitter/X;
- Reddit;
- other platforms supported by the installed yt-dlp version.

Do not build separate downloader implementations for every platform.

Do not bypass:

- DRM;
- paywalls;
- authentication;
- access controls.

If yt-dlp does not support a URL, return a clear error.

---

# 6. BACKEND

Use:

- PHP 8.3+;
- Composer;
- PSR-4;
- PSR-12;
- strict types;
- typed properties;
- typed parameters and returns;
- dependency injection;
- DTOs where useful;
- services;
- structured exceptions.

Do not create a giant `index.php`.

Do not mix business logic, routing, HTML, configuration, and downloader execution in one file.

Keep responsibilities separated.

---

# 7. FRONTEND

Prefer:

- HTML5;
- CSS;
- vanilla JavaScript.

Do not introduce React, Vue, Next.js, or another frontend framework unless there is a strong architectural reason.

The frontend must be:

- responsive;
- accessible;
- fast;
- maintainable.

Use semantic HTML.

---

# 8. ARCHITECTURE

Prefer a clear separation similar to:

```text
Frontend
    ↓
HTTP/API
    ↓
Controllers
    ↓
Services
    ↓
Download subsystem
    ↓
yt-dlp / ffmpeg
    ↓
Temporary storage
```

This is not rigid. If another architecture is clearly better, explain why and use it.

Avoid abstraction for abstraction's sake.

---

# 9. YT-DLP INTEGRATION

yt-dlp is an external CLI application.

Never construct unsafe shell commands from user input.

Never concatenate a user URL into a shell command.

Use safe process execution.

The implementation must handle:

- argument escaping;
- process timeouts;
- stdout;
- stderr;
- exit codes;
- cancellation;
- resource limits;
- temporary directories;
- cleanup.

Prefer structured JSON output from yt-dlp where possible.

Do not rely on fragile human-readable CLI parsing.

Isolate yt-dlp behind a dedicated service/interface.

---

# 10. FFMPEG

Use ffmpeg when necessary to:

- merge separate audio/video streams;
- process media;
- produce the final downloadable file.

Support both:

```text
yt-dlp → final file
```

and:

```text
yt-dlp → separate streams → ffmpeg → final file
```

Do not invoke ffmpeg unnecessarily.

---

# 11. API

Design a clean REST-style API.

Possible endpoints:

```text
POST /api/analyze
POST /api/download
GET  /api/download/{id}/status
GET  /api/download/{id}/file
```

These are examples, not mandatory final endpoints.

Choose the best structure.

For every endpoint define:

- method;
- URL;
- request schema;
- validation;
- response schema;
- HTTP status codes;
- errors;
- rate limiting.

Use a consistent JSON error format, for example:

```json
{
  "error": {
    "code": "UNSUPPORTED_SOURCE",
    "message": "This video source is not supported."
  }
}
```

---

# 12. VIDEO ANALYSIS

The analyze operation should:

1. receive a URL;
2. validate it;
3. verify it is an acceptable source;
4. safely invoke yt-dlp;
5. obtain metadata;
6. validate/sanitize metadata;
7. return safe data.

Never trust external metadata.

Treat fields such as title, uploader, description, filename, and thumbnail URL as untrusted input.

Never use untrusted metadata directly as a filesystem path.

---

# 13. DOWNLOAD ARCHITECTURE

Analyze both:

### Synchronous

Download during the HTTP request.

### Asynchronous

Create a job and process it through a worker.

Because downloads may be large and long-running, prefer an architecture that can support asynchronous processing.

However, do not over-engineer the MVP.

If a safe synchronous implementation is appropriate initially, it may be used.

The architecture must allow migration to a worker/queue system later without a full rewrite.

Consider:

- PHP request timeouts;
- large files;
- concurrent downloads;
- CPU;
- RAM;
- disk;
- bandwidth.

---

# 14. SECURITY

Security is a first-class requirement.

Protect against:

## Command Injection

User input must never become arbitrary shell syntax.

## SSRF

ClipHunter must not become a generic proxy or internal-network downloader.

Protect against:

- localhost;
- 127.0.0.1;
- private IPv4;
- private IPv6;
- loopback;
- link-local;
- cloud metadata endpoints;
- internal hostnames;
- `file://`;
- dangerous protocols.

Define a safe URL validation strategy.

## Path Traversal

Protect against:

- `../`;
- absolute paths;
- path separators;
- malicious filenames;
- filesystem escape.

Never use external filenames directly as filesystem paths.

## Resource Exhaustion

Protect against:

- huge downloads;
- extremely long videos;
- unlimited concurrent processes;
- disk exhaustion;
- memory exhaustion;
- CPU exhaustion.

## XSS

External metadata is untrusted.

Escape it correctly before rendering.

## CSRF

Determine whether CSRF protection is required by the final architecture.

## MIME Spoofing

Do not blindly trust client-provided MIME types.

## DoS

Implement:

- rate limits;
- concurrency limits;
- timeouts;
- resource limits.

---

# 15. RESOURCE LIMITS

Define configurable limits for:

- maximum file size;
- maximum video duration;
- analyze timeout;
- download timeout;
- maximum concurrent downloads;
- maximum requests/downloads per IP;
- temporary storage capacity.

Use environment variables.

Choose sensible MVP defaults.

---

# 16. STORAGE

Temporary files must not be stored in the public web root.

Possible structure:

```text
storage/
├── temp/
├── downloads/
└── logs/
```

The final structure may differ.

Implement automatic cleanup.

Define a retention policy for generated files.

Use restrictive filesystem permissions.

---

# 17. DATABASE

Do not introduce a database without a real requirement.

First determine whether the MVP can operate without persistent storage.

If a database is necessary, explain:

- why;
- which database;
- schema;
- indexes;
- retention;
- cleanup.

Do not add accounts, subscriptions, payments, or complex analytics unless explicitly required.

---

# 18. FRONTEND STATES

Clearly represent:

1. Idle
2. Analyzing
3. Result
4. Downloading
5. Completed
6. Error

The user must always understand what the application is doing.

---

# 19. LANDING PAGE

Build the page around the primary action.

Potential sections:

- Header;
- Hero;
- URL input;
- Supported platforms;
- How it works;
- Features;
- FAQ;
- Footer.

Do not add sections merely to make the page longer.

Use concise Russian copy.

Brand:

**ClipHunter**

The primary CTA must be immediately obvious.

---

# 20. DESIGN SYSTEM

Define tokens for:

- background;
- surface;
- primary;
- secondary;
- text;
- muted text;
- border;
- success;
- warning;
- error;
- radius;
- shadows;
- spacing;
- typography;
- container widths.

Use a restrained palette.

---

# 21. RESPONSIVE DESIGN

Support:

- mobile;
- tablet;
- desktop.

Pay special attention to:

- URL input;
- CTA;
- result card;
- format selector;
- download button;
- navigation.

No unwanted horizontal overflow.

---

# 22. ACCESSIBILITY

Implement:

- semantic HTML;
- keyboard navigation;
- visible focus states;
- labels;
- appropriate ARIA;
- sufficient contrast;
- sensible motion;
- reduced-motion consideration.

---

# 23. CONFIGURATION

Use environment variables.

Possible configuration:

```text
APP_ENV
APP_URL
YTDLP_PATH
FFMPEG_PATH
STORAGE_PATH
MAX_FILE_SIZE
MAX_VIDEO_DURATION
ANALYZE_TIMEOUT
DOWNLOAD_TIMEOUT
MAX_CONCURRENT_DOWNLOADS
RATE_LIMIT
```

Determine the final list yourself.

Create:

```text
.env.example
```

Never commit `.env`.

Never hardcode production secrets.

---

# 24. LOGGING

Use structured logging.

Logs should allow diagnosis of:

- requests;
- jobs;
- yt-dlp execution;
- execution duration;
- exit codes;
- errors;
- cleanup.

Never log:

- passwords;
- tokens;
- private keys;
- secrets;
- unnecessary personal data.

---

# 25. ERROR HANDLING

Handle explicitly:

- invalid URL;
- unsupported source;
- unavailable video;
- private video;
- deleted video;
- yt-dlp failure;
- ffmpeg failure;
- timeout;
- file too large;
- rate limit;
- storage failure;
- internal error.

Users receive clear messages.

Technical details belong in logs.

Never expose stack traces or internal filesystem paths.

---

# 26. TESTING

Create a testing strategy covering:

- unit tests;
- integration tests;
- API tests;
- security tests;
- yt-dlp integration;
- edge cases.

At minimum test:

- malformed URL;
- unsupported URL;
- malicious URL;
- SSRF attempts;
- oversized file;
- timeout;
- yt-dlp failure;
- ffmpeg failure;
- cleanup;
- concurrent downloads;
- invalid metadata;
- path traversal.

---

# 27. PROJECT STRUCTURE

Use a clean structure appropriate for the final architecture.

Possible structure:

```text
/public
/src
/config
/routes
/resources
/storage
/tests
/vendor
```

Keep application source outside the public web root.

Explain important directories/files in the architecture document.

---

# 28. CODE QUALITY

Production code must follow:

- strict typing;
- PSR-12;
- SOLID where justified;
- DRY;
- dependency injection;
- typed interfaces;
- meaningful naming;
- focused classes;
- focused methods;
- no hardcoded secrets;
- no unnecessary abstractions.

Prefer the simplest robust solution.

---

# 29. DEPLOYMENT TARGET

Target:

- Linux;
- Nginx;
- PHP-FPM;
- Composer;
- yt-dlp;
- ffmpeg;
- HTTPS;
- DuckDNS.

Nginx must expose only the public web directory.

Internal directories and `.env` must never be directly accessible over HTTP.

---

# 30. DOCKER

Evaluate:

1. Native Linux.
2. Docker Compose.

Choose one primary strategy.

Do not use Kubernetes.

If Docker is chosen, keep it simple.

---

# 31. LEGAL / PRODUCT

Include appropriate:

- Terms;
- Privacy;
- Abuse policy.

Clearly state that users are responsible for having the right to download requested content.

Do not implement DRM, paywall, or access-control bypass functionality.

---

# 32. MVP

Implement:

- landing page;
- URL input;
- URL validation;
- yt-dlp integration;
- metadata extraction;
- format selection;
- download;
- temporary storage;
- cleanup;
- rate limiting;
- error handling;
- responsive UI.

Do not implement unless explicitly requested:

- accounts;
- payments;
- subscriptions;
- complex analytics;
- microservices;
- Kubernetes;
- unnecessary database;
- unnecessary frontend framework.

---

# 33. DEVELOPMENT WORKFLOW

Work iteratively.

For each significant stage:

1. Inspect the project.
2. Create a small implementation plan.
3. Implement.
4. Run tests/checks.
5. Fix failures.
6. Review security.
7. Review UX.
8. Commit stable work.
9. Push to GitHub.
10. Continue.

Do not generate the entire project as one giant operation.

Do not rewrite working code unnecessarily.

---

# 34. IMPORTANT DECISIONS

For materially different architectural choices:

1. Explain alternatives.
2. Explain advantages/disadvantages.
3. Recommend one.
4. Ask for confirmation when the decision has significant consequences.

For small implementation details, make the decision yourself.

Destructive operations require explicit confirmation.

---

# 35. GITHUB

GitHub is the primary remote.

If GitHub CLI is already authenticated:

1. Verify authentication.
2. Check whether the ClipHunter repository exists.
3. If it does not exist, create a PRIVATE repository.
4. Initialize Git if necessary.
5. Create `.gitignore`.
6. Verify secrets/temp files are excluded.
7. Configure `origin`.
8. Create initial commit.
9. Push.

Use existing GitHub CLI/SSH credentials.

Never ask the project owner to paste:

- GitHub tokens;
- private SSH keys;
- passwords;
- passphrases

into chat.

Never commit:

- `.env`;
- credentials;
- tokens;
- private keys;
- downloaded media;
- temporary files.

Before every push:

1. Run `git status`.
2. Inspect staged files.
3. Verify no secrets are included.

Never force-push without explicit authorization.

Never delete the repository without explicit authorization.

---

# 36. COMMIT POLICY

Use small logical commits.

Examples:

```text
feat: add yt-dlp metadata extraction
feat: add download service
feat: add responsive landing page
feat: add format selector
fix: prevent unsafe download paths
fix: handle yt-dlp timeout
refactor: extract downloader service
```

After every completed and tested feature/milestone:

1. Review changes.
2. Run relevant tests.
3. Commit.
4. Push stable work.

Do not create giant commits containing unrelated changes.

---

# 37. PRODUCTION SERVER

Use the existing SSH environment.

Never ask the project owner to paste private SSH credentials into chat.

Before deployment inspect:

1. SSH connectivity.
2. Server OS.
3. sudo availability.
4. Nginx.
5. PHP/PHP-FPM.
6. Composer.
7. yt-dlp.
8. ffmpeg.
9. Git.
10. Disk space.
11. System resources.

Install missing dependencies when appropriate and authorized.

---

# 38. PRODUCTION DIRECTORY

Do not arbitrarily choose a production directory.

Use an existing deployment path if one is already configured.

If no path exists and it cannot safely be determined, ask.

Possible example:

```text
/var/www/cliphunter
```

This is only an example.

---

# 39. DEPLOYMENT FLOW

Preferred flow:

```text
Local development
        ↓
Git commit
        ↓
GitHub
        ↓
Production server
        ↓
Pull/deploy
        ↓
Install dependencies
        ↓
Configure environment
        ↓
Restart required services
        ↓
Health checks
```

Prefer Git-based deployment over manually copying files when practical.

---

# 40. SERVER CONFIGURATION

Configure:

- Nginx;
- PHP-FPM;
- Composer;
- yt-dlp;
- ffmpeg;
- storage;
- permissions;
- logs;
- cleanup;
- workers if required;
- HTTPS.

Nginx must point to the public web root.

Internal project files must not be directly accessible.

---

# 41. PRODUCTION ENVIRONMENT

Keep development and production separate.

Do not copy the development `.env` blindly to production.

Configure production environment variables securely on the server.

Never commit production secrets.

---

# 42. DEPLOYMENT VERIFICATION

After deployment:

1. Verify HTTP.
2. Verify frontend.
3. Verify API.
4. Verify analysis.
5. Verify yt-dlp.
6. Verify download.
7. Verify ffmpeg when applicable.
8. Check application logs.
9. Check Nginx logs.
10. Check PHP-FPM logs.

Deployment is not complete until the core download flow works.

---

# 43. ROLLBACK

Every production deployment must correspond to a known Git commit.

Maintain the ability to return to the previous stable version.

Do not destroy the previous working state until the new deployment is verified.

---

# 44. DESTRUCTIVE OPERATIONS

Require explicit confirmation before:

- `rm -rf`;
- deleting production files;
- deleting databases;
- deleting Docker volumes;
- changing firewall rules;
- changing SSH configuration;
- force pushing;
- deleting repositories;
- destroying production infrastructure.

Never assume a destructive operation is safe.

---

# 45. DEVELOPMENT CYCLE

Follow:

```text
PLAN
↓
IMPLEMENT
↓
TEST
↓
REVIEW
↓
COMMIT
↓
PUSH
↓
NEXT FEATURE
```

After MVP:

```text
FINAL TEST
↓
FINAL COMMIT
↓
PUSH
↓
DEPLOY
↓
HEALTH CHECK
↓
REPORT
```

---

# 46. ARCHITECTURE PLAN

Before full implementation, produce an architecture document containing:

1. Executive Summary.
2. Architecture Overview.
3. Technology Stack.
4. UX Flow.
5. API Specification.
6. yt-dlp Integration.
7. ffmpeg Integration.
8. Security / Threat Model.
9. Resource Limits.
10. Storage Architecture.
11. Database Decision.
12. Frontend Architecture.
13. Design System.
14. Project Structure.
15. Configuration.
16. Logging.
17. Testing Strategy.
18. Deployment.
19. Docker Decision.
20. MVP Scope.
21. Phase 2.
22. Phase 3.
23. Implementation Roadmap.
24. Definition of Done.
25. Risks and Mitigations.

Do not start large-scale implementation before completing this architecture stage.

---

# 47. DEFINITION OF DONE

The MVP is complete only when:

- architecture is implemented;
- landing page is complete;
- responsive UI works;
- URL validation works;
- yt-dlp integration works;
- metadata extraction works;
- format selection works;
- download works;
- ffmpeg works where required;
- temporary files are cleaned;
- rate limiting exists;
- security checks pass;
- tests pass;
- GitHub repository exists;
- project is pushed to GitHub;
- production environment is configured;
- Nginx works;
- PHP-FPM works;
- HTTPS works;
- yt-dlp works in production;
- ffmpeg works in production;
- core download flow works in production.

---

# 48. GENERAL PRINCIPLE

Build a real, maintainable product.

Optimize for:

- correctness;
- security;
- maintainability;
- simplicity;
- performance;
- UX;
- reliability;
- clean architecture.

When a simple solution is sufficient, prefer it.

When a complex solution is genuinely necessary, explain why.

Never sacrifice security for convenience.

---

# 49. FIRST ACTION

Your first task is NOT to immediately implement the application.

First inspect the environment, read this file completely, inspect `design-reference.png`, verify the available development/deployment tooling, and produce the complete architecture and implementation plan.

After the architecture stage, proceed with implementation in small tested stages, committing and pushing each stable milestone to GitHub.

The ultimate goal is not merely source code.

The goal is:

**working code + tests + GitHub + secure deployment + Nginx + HTTPS + yt-dlp + ffmpeg + working download flow.**
