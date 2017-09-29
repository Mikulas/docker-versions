<?php

/**
 * @return string[]|generator
 */
function getTags(string $repo): generator {
	if (strpos($repo, 'gcr.io') !== FALSE) {
		return getGCRTags($repo);
	} else {
		return getDockerHubTags($repo);
	}
}

function getDockerHubTags(string $repo): generator {
	list($org, $project) = explode('/', $repo, 2) + [NULL, NULL];
	$parts = [urlencode($org)];
	if ($project) {
		$parts[] = '/';
		$parts[] = urlencode($project);
	}

	$url = sprintf('https://registry.hub.docker.com/v1/repositories/%s/tags', implode('', $parts));
	$data = file_get_contents($url);
	$payload = json_decode($data, TRUE);
	foreach ($payload as $tag) {
		yield $tag['name'];
	}
}

function getGCRTags(string $repo): generator {
	[$_, $org, $project] = explode('/', $repo, 3);
	$url = sprintf('https://gcr.io/v2/%s/%s/tags/list', urlencode($org), urlencode($project));
	$data = file_get_contents($url);
	$payload = json_decode($data, TRUE);
	yield from $payload['tags'];
}

function getLastVersion(string $repo): ?string {
	$max = NULL;
	foreach (getTags($repo) as $tag) {
		if (preg_match('~\brc\b~i', $tag)) {
			// ignore unparsable python:3.7-rc-alpine3.6
			continue;
		}
		$parts = explode('.', $tag, 3);
		if (preg_match('~^\d+-?(a(lpha)?|b(eta)?|dev|rc)~', end($parts))) {
			// ignore prerelease tag
			continue;
		}

		if (version_compare($tag, $max, '>')) {
			$max = $tag;
		}
	}
	return $max;
}

$config = yaml_parse_file(__DIR__ . '/conf.yaml');

foreach ($config['watch'] as $repo => $data) {
	$latest = getLastVersion($repo);
	$prefix = "$repo $data[version]";
	if (version_compare($data['version'], $latest, '<')) {
		echo "\e[0;31m";
		echo "$prefix is outdated: latest is $latest\n";
		$uses = $data['uses'] ?? [];
		if ($uses) {
			echo "  used in " . implode(', ', $uses) . "\n";
		}
		echo "\e[0m";

	} else {
		echo "$prefix is up-to-date\n";
	}
}
