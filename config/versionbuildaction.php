<?php

// config for Vortechstudio/VersionBuildAction
return [
    "gh_token" => env("GITHUB_TOKEN", null),
    "gh_owner" => env("GITHUB_USERNAME", null),
    "gh_repository" => env("GITHUB_REPOSITORY", null),
];
