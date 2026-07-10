<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\UserSettings;
use App\System\DmPrivacy;

function verifySame($expected, $actual, $label, array &$failures)
{
    if ($expected !== $actual) {
        $failures[] = $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
}

function invokePrivateStatic($class, $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs(null, $arguments);
}

$failures = [];

try {
    $themeConfig = UserSettings::getThemeConfig();
    verifySame(true, isset($themeConfig['modes']['archive']), 'Eye Comfort Mode remains a valid theme key', $failures);
    verifySame(true, isset($themeConfig['colors']['amber']), 'Amber remains a valid accent key', $failures);
    verifySame(true, isset($themeConfig['eyeComfortLevels']['light']), 'Light eye comfort level is valid', $failures);
    verifySame(true, isset($themeConfig['eyeComfortLevels']['balanced']), 'Balanced eye comfort level is valid', $failures);
    verifySame(true, isset($themeConfig['eyeComfortLevels']['intense']), 'Intense eye comfort level is valid', $failures);

    $parseTheme = function ($value) {
        return invokePrivateStatic(UserSettings::class, 'parseThemeString', [$value]);
    };

    $normalTheme = $parseTheme('dark_blue_balanced');
    verifySame('dark', $normalTheme['mode'], 'Valid theme mode is preserved', $failures);
    verifySame('blue', $normalTheme['color'], 'Valid accent color is preserved', $failures);
    verifySame('balanced', $normalTheme['eye_comfort_level'], 'Valid eye comfort level is preserved', $failures);

    $legacyTheme = $parseTheme('oled_cyan_light');
    verifySame('command', $legacyTheme['mode'], 'Legacy theme mode falls back safely', $failures);
    verifySame('blue', $legacyTheme['color'], 'Legacy accent color falls back safely', $failures);
    verifySame('light', $legacyTheme['eye_comfort_level'], 'Legacy theme retains valid eye comfort level', $failures);

    $eyeComfortTheme = $parseTheme('archive_purple_intense');
    verifySame('archive', $eyeComfortTheme['mode'], 'Eye Comfort Mode key is preserved', $failures);
    verifySame('amber', $eyeComfortTheme['color'], 'Eye Comfort Mode locks the warm accent', $failures);
    verifySame('intense', $eyeComfortTheme['eye_comfort_level'], 'Intense eye comfort level is preserved', $failures);

    $invalidTheme = $parseTheme('unknown_invalid_unknown');
    verifySame('dark', $invalidTheme['mode'], 'Invalid theme mode uses default', $failures);
    verifySame('purple', $invalidTheme['color'], 'Invalid accent color uses default', $failures);
    verifySame('balanced', $invalidTheme['eye_comfort_level'], 'Invalid eye comfort level uses default', $failures);

    $languages = UserSettings::getLanguageConfig();
    verifySame(true, isset($languages['tr']), 'Turkish locale is available', $failures);
    verifySame(true, isset($languages['en']), 'English locale is available', $failures);
    verifySame(false, isset($languages['invalid']), 'Invalid locale is rejected by configuration', $failures);

    foreach ([DmPrivacy::ALLOW_EVERYONE, DmPrivacy::ALLOW_PARTY, DmPrivacy::ALLOW_NOBODY] as $allowFrom) {
        verifySame($allowFrom, invokePrivateStatic(DmPrivacy::class, 'normalizeAllowFrom', [$allowFrom]), 'Valid DM privacy value is preserved: ' . $allowFrom, $failures);
    }
    verifySame(DmPrivacy::ALLOW_EVERYONE, invokePrivateStatic(DmPrivacy::class, 'normalizeAllowFrom', ['approved_only']), 'Preview-only DM privacy value is not persisted', $failures);
    verifySame(DmPrivacy::ALLOW_EVERYONE, invokePrivateStatic(DmPrivacy::class, 'normalizeAllowFrom', ['invalid']), 'Invalid DM privacy value uses default', $failures);
} catch (Exception $exception) {
    $failures[] = 'Verification could not run: ' . $exception->getMessage();
}

if (!empty($failures)) {
    fwrite(STDERR, "Settings fallback verification failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Settings fallback verification passed.\n";
