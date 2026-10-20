<?php

namespace App\Services;

use App\Models\Country;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Bulk-imports jobs from CSV/XLSX. Each row is a distinct job the user found; rows are validated
 * individually and duplicates of existing jobs (same URL, or same company + position) are skipped.
 */
class JobImporter
{
    public const MAX_ROWS = 500;

    /** Accepted header names (lowercased, spaces/underscores ignored) per field. */
    private const HEADERS = [
        'position' => ['position', 'title', 'jobtitle', 'role'],
        'company' => ['company', 'companyname', 'employer'],
        'description' => ['description', 'jobdescription', 'jd'],
        'url' => ['url', 'joburl', 'link'],
        'location' => ['location', 'city'],
        'country' => ['country', 'headquarter', 'headquarters', 'hq'],
        'contact_email' => ['contactemail', 'email', 'recruiteremail'],
        'cc_emails' => ['cc', 'ccemail', 'ccemails'],
        'source' => ['source'],
        'website' => ['website', 'web', 'site', 'homepage'],
        'industry' => ['industry', 'sector'],
        'specialties' => ['specialties', 'specialities', 'specialty', 'services'],
    ];

    /**
     * @return array{imported: int, duplicates: list<int>, errors: list<array{row: int, messages: list<string>}>, unsupported_countries: array<string, list<int>>, job_ids: list<int>}
     */
    public function import(User $user, string $path, string $extension): array
    {
        $rows = $this->read($path, $extension, 'description');
        $result = ['imported' => 0, 'duplicates' => [], 'errors' => [], 'unsupported_countries' => [], 'job_ids' => []];
        $seen = [];

        foreach ($rows as $rowNumber => $row) {
            $row = $this->resolveCountry($row, $rowNumber, $result);
            if ($row === null) {
                continue;
            }
            $row['cc_emails'] = JobPosting::parseEmails($row['cc_emails'] ?? null);
            $validator = Validator::make($row, JobPosting::validationRules());
            if ($validator->fails()) {
                $result['errors'][] = ['row' => $rowNumber, 'messages' => array_values($validator->errors()->all())];

                continue;
            }
            $data = $validator->validated();
            $key = JobPosting::duplicateKey($data);
            if (isset($seen[$key]) || JobPosting::findDuplicate($user, $data)) {
                $result['duplicates'][] = $rowNumber;

                continue;
            }
            $seen[$key] = true;
            $result['job_ids'][] = $user->jobPostings()->create($data)->id;
            $result['imported']++;
        }

        return $result;
    }

    /**
     * Import a company list for speculative applications: one row per company, offering the role the user
     * chose, with the chosen resume. One company per email domain; companies already in JobHunter
     * (any job with an address at the same domain) are skipped so nobody is written to twice.
     *
     * @return array{imported: int, duplicates: list<int>, errors: list<array{row: int, messages: list<string>}>, unsupported_countries: array<string, list<int>>, job_ids: list<int>}
     */
    public function importCompanies(User $user, string $path, string $extension, string $position, int $resumeId): array
    {
        $rows = $this->read($path, $extension, 'contact_email');
        $result = ['imported' => 0, 'duplicates' => [], 'errors' => [], 'unsupported_countries' => [], 'job_ids' => []];
        $known = $user->jobPostings()->whereNotNull('contact_email')->pluck('contact_email')
            ->map(fn ($e) => JobPosting::emailDomain($e))->filter()->flip()->all();

        foreach ($rows as $rowNumber => $row) {
            $row = $this->resolveCountry($row, $rowNumber, $result);
            if ($row === null) {
                continue;
            }
            $email = $row['contact_email'] ?? null;
            $domain = JobPosting::emailDomain($email);
            if ($email === null || $domain === null) {
                $result['errors'][] = ['row' => $rowNumber, 'messages' => ['No email address for this company.']];

                continue;
            }
            $data = [
                'kind' => JobPosting::KIND_SPECULATIVE,
                'position' => $position,
                'company' => $row['company'] ?? null,
                'contact_email' => $email,
                'contact_flag' => JobPosting::contactFlagFor($email),
                'country_id' => $row['country_id'] ?? null,
                'website' => $row['website'] ?? null,
                'industry' => isset($row['industry']) ? mb_substr($row['industry'], 0, 500) : null,
                'specialties' => $row['specialties'] ?? null,
                'source' => $row['source'] ?? 'Company list',
                'preferred_resume_id' => $resumeId,
            ];
            $data['description'] = self::companySummary($data);
            $validator = Validator::make($data, [
                'company' => ['required', 'string', 'max:255'],
                'contact_email' => ['email:rfc,strict', 'max:255'],
                'website' => ['nullable', 'string', 'max:2048'],
                'source' => ['nullable', 'string', 'max:100'],
            ]);
            if ($validator->fails()) {
                $result['errors'][] = ['row' => $rowNumber, 'messages' => array_values($validator->errors()->all())];

                continue;
            }
            if (isset($known[$domain])) {
                $result['duplicates'][] = $rowNumber;

                continue;
            }
            $known[$domain] = true;
            $result['job_ids'][] = $user->jobPostings()->create($data)->id;
            $result['imported']++;
        }

        return $result;
    }

    /**
     * What the company list says, as the job's description (what the reviewer sees; nothing invented).
     *
     * @param  array<string, mixed>  $data
     */
    private static function companySummary(array $data): string
    {
        return collect([
            'Speculative application: the company has not posted this role.',
            'Company: '.($data['company'] ?? ''),
            $data['industry'] ? 'Industry: '.$data['industry'] : null,
            $data['specialties'] ? 'Specialties: '.$data['specialties'] : null,
            $data['website'] ? 'Website: '.$data['website'] : null,
        ])->filter()->implode("\n");
    }

    /** @return array<int, array<string, string|null>> keyed by spreadsheet row number */
    private function read(string $path, string $extension, string $requiredField): array
    {
        $reader = strtolower($extension) === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $rows = [];
        $map = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $index => $row) {
                $cells = array_map($this->cellText(...), $row->toArray());
                if ($map === null) {
                    $map = $this->headerMap($cells);

                    continue;
                }
                if (count(array_filter($cells, fn ($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }
                $record = [];
                foreach ($map as $column => $field) {
                    $value = $cells[$column] ?? null;
                    $record[$field] = $value === '' ? null : $value;
                }
                $rows[$index] = $record;
                if (count($rows) >= self::MAX_ROWS) {
                    break 2;
                }
            }
            break; // first sheet only
        }
        $reader->close();

        if ($requiredField === 'description' && $map !== null && ! in_array('description', $map, true) && in_array('contact_email', $map, true)) {
            throw new \InvalidArgumentException('This looks like a company list (it has emails but no job descriptions). Choose "Companies (speculative)" at the top of this page, enter the position and resume, then upload it again.');
        }
        if ($map === null || ! in_array($requiredField, $map, true)) {
            $column = $requiredField === 'contact_email' ? 'email' : $requiredField;
            throw new \InvalidArgumentException("The file needs a header row with at least a \"{$column}\" column.");
        }

        return $rows;
    }

    /**
     * Turn the spreadsheet's country text (name, code or alias like "Dubai") into country_id.
     *
     * @param  array<string, string|null>  $row
     * @param  array{imported: int, duplicates: list<int>, errors: list<array{row: int, messages: list<string>}>, unsupported_countries: array<string, list<int>>, job_ids: list<int>}  $result
     * @return array<string, mixed>|null null when the country is not supported
     */
    private function resolveCountry(array $row, int $rowNumber, array &$result): ?array
    {
        $text = $row['country'] ?? null;
        unset($row['country']);
        if ($text === null || $text === '') {
            return $row;
        }
        $country = Country::resolve($text);
        if ($country === null) {
            // Grouped per country: one line per unsupported country, not one per row.
            $result['unsupported_countries'][$text][] = $rowNumber;

            return null;
        }

        return [...$row, 'country_id' => $country->id];
    }

    private function cellText(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_scalar($value) => trim((string) $value),
            default => null,
        };
    }

    /**
     * @param  array<int, string|null>  $headers
     * @return array<int, string> column index => field
     */
    private function headerMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $header) {
            $normalized = str_replace([' ', '_', '-'], '', strtolower((string) $header));
            foreach (self::HEADERS as $field => $aliases) {
                if (in_array($normalized, $aliases, true) && ! in_array($field, $map, true)) {
                    $map[$i] = $field;
                }
            }
        }

        return $map;
    }
}
