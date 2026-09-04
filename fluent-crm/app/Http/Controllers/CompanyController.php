<?php

namespace FluentCrm\App\Http\Controllers;

use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\App\Models\Company;
use FluentCrm\App\Models\CompanyNote;
use FluentCrm\App\Models\CustomCompanyField;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SubscriberNote;
use FluentCrm\App\Services\AutoSubscribe;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\FileSystem;
use FluentCrm\App\Services\Sanitize;
use FluentCrm\Framework\Http\Request\Request;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Support\Collection;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $order = [
            'by'    => Helper::sanitizeOrderBy($request->get('sort_by'), 'id'),
            'order' => Helper::sanitizeOrderBy($request->get('sort_order'), 'DESC')
        ];

        $companies = Company::orderBy($order['by'], $order['order'])
            ->with(['owner'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'));

        $inlineFilters = $request->get('inline_filters', []);

        if ($inlineFilters && is_array($inlineFilters)) {
            $inlineFilters = array_filter($inlineFilters);

            foreach ($inlineFilters as $key => $values) {
                if (!is_array($values)) {
                    continue;
                }
                $values = array_map('sanitize_text_field', $values);

                if ($key == 'company_categories') {
                    $companies->whereIn('industry', $values);
                } else if ($key == 'company_types') {
                    $companies->whereIn('type', $values);
                }
            }
        }

        $companies = $companies->paginate();

        foreach ($companies as $company) {
            $company->contacts_count = $company->getContactsCount();
        }

        return [
            'companies' => $companies
        ];
    }

    public function searchCompanies(Request $request)
    {
        $search = $request->getSafe('search', 'sanitize_text_field');
        $companies = Company::orderBy('name', 'ASC')
            ->searchBy($search);

        $subscriberId = $request->getSafe('subscriber_id', 'intval');

        if ($subscriberId) {
            $companies = $companies->doesnthave('subscribers', 'and', function ($query) use ($subscriberId) {
                $query->where('fc_subscribers.id', $subscriberId);
            });
        }

        $companies = $companies->limit(50)->get();

        $formatted = [];

        $values = (array)$request->get('values', []);

        $pushedIds = [];

        foreach ($companies as $company) {
            $pushedIds[] = $company->id;
            $formatted[] = [
                'id'      => $company->id,
                'name'    => $company->name,
                'email'   => $company->email,
                'logo'    => $company->logo,
                'phone'   => $company->phone,
                'website' => $company->website
            ];
        }

        if ($values && $newIds = array_diff($values, $pushedIds)) {
            $newItems = Company::whereIn('id', $newIds)
                ->get();
            foreach ($newItems as $item) {
                $formatted[] = [
                    'id'      => $item->id,
                    'name'    => $item->name,
                    'email'   => $item->email,
                    'logo'    => $item->logo,
                    'phone'   => $item->phone,
                    'website' => $item->website
                ];
            }
        }

        return [
            'results'  => $formatted,
            'has_more' => Company::count() >= 50
        ];
    }

    public function searchUnattachedContacts(Request $request)
    {
        $search = $request->getSafe('search', 'sanitize_text_field');
        $companyId = $request->getSafe('company_id', 'intval', '');

        $contacts = Subscriber::orderBy('id', 'DESC')
            ->searchBy($search)
            ->whereDoesntHave('companies', function ($query) use ($companyId) {
                $query->where('fc_companies.id', $companyId);
            })
            ->limit($request->getSafe('limit', 'intval', 20))
            ->get();

        return [
            'results' => $contacts
        ];
    }

    public function attachSubscribers(Request $request)
    {
        $subscriberIds = $request->get('subscriber_ids');
        $companyIds = $request->get('company_ids');

        $result = FluentCrmApi('companies')->attachContactsByIds($subscriberIds, $companyIds);

        if (!$result) {
            return $this->sendError('Invalid data', 422);
        }

        return [
            'message'   => __('Selected Companies have been attached successfully', 'fluent-crm'),
            'companies' => $result['companies']
        ];
    }

    public function detachSubscribers(Request $request)
    {
        $subscriberIds = $request->get('subscriber_ids');
        $companyIds = $request->get('company_ids');

        $result = FluentCrmApi('companies')->detachContactsByIds($subscriberIds, $companyIds);

        if (!$result) {
            return $this->sendError('Invalid data', 422);
        }
        $result['message'] = __('Company has been successfully detached', 'fluent-crm');

        return $result;
    }

    /**
     * Find a company.
     */
    public function find(Request $request, $id)
    {

        $findBy = $request->getSafe('find_by', 'sanitize_text_field', 'id');
        $findByValue = $request->getSafe('find_by_value', 'sanitize_text_field');

        $customFindBys = ['name', 'email', 'phone'];

        if (in_array($findBy, $customFindBys)) {
            $company = Company::where($findBy, $findByValue)->first();
            if (!$company) {
                return $this->sendError('Company not found', 422);
            }
        } else {
            $company = Company::findOrFail($id);
        }

        $company->load(['owner']);
        if ($company->owner) {
            $company->owner->stats = $company->owner->stats();
        }

        $company->contacts_count = $company->getContactsCount();

        return [
            'company' => $company
        ];
    }

    /**
     * Store a company.
     * @param Request $request
     * @return \WP_REST_Response | array
     */
    public function create(Request $request)
    {
        $allData = $request->all();

        $allData = $this->validate($allData, [
            'name' => 'required|unique:fc_companies,name'
        ]);

        $data = $this->getSanitizedData($allData);

        if (empty($data['logo']) && !empty($allData['website']) && Helper::isExperimentalEnabled('company_auto_logo')) {
            $data['logo'] = $this->getLogoWebsiteUrl($allData['website']);
        }

        $company = FluentCrmApi('companies')->createOrUpdate($data);

        if ($contactId = $request->getSafe('intended_contact_id', 'intval')) {
            $contact = Subscriber::find($contactId);
            if ($contact) {
                $contact->attachCompanies([$company->id]);
                if (!$contact->company_id) {
                    $contact->company_id = $company->id;
                    $contact->save();
                }
            }
        }

        return [
            'message' => __('Company has been created successfully', 'fluent-crm'),
            'company' => $company
        ];
    }

    public function update(Request $request, $id = 0)
    {
        if ($id == 0) {
            return $this->create($request);
        }

        $company = Company::findOrFail($id);

        $allData = $request->all();

        $name = sanitize_text_field($allData['name']);

        if (Company::where('id', '!=', $id)->where('name', $name)->first()) {
            return $this->sendError([
                'message' => __('Company name already exists. Please use a different company name', 'fluent-crm')
            ], 422);
        }

        $data = $this->getSanitizedData($allData);

        $company = FluentCrmApi('companies')->createOrUpdate($data);

        return [
            'message' => __('Company has been updated', 'fluent-crm'),
            'company' => $company
        ];

    }

    public function updateProperty()
    {
        $column = $this->request->getSafe('property', 'sanitize_text_field');
        $value = $this->request->getSafe('value', 'sanitize_text_field');
        $companyIds = $this->request->get('companies');
        
        if (!is_array($companyIds)) {
            $companyIds = [$companyIds];
        }
        $companyIds = array_map('intval', $companyIds);
        $companyIds = array_filter($companyIds);

        $validColumns = ['type', 'logo', 'owner_id', 'refetch_logo'];
        $types = Helper::companyTypes();
        $statuses = Helper::companyTypes();

        $this->validate([
            'column'      => $column,
            'value'       => $value,
            'company_ids' => $companyIds
        ], [
            'column'      => 'required',
            'value'       => 'required',
            'company_ids' => 'required'
        ]);

        if (!in_array($column, $validColumns)) {
            return $this->sendError([
                'message' => __('Column is not valid', 'fluent-crm')
            ]);
        }

        if ($column == 'type' && !in_array($value, $types)) {
            return $this->sendError([
                'message' => __('Value is not valid', 'fluent-crm')
            ]);
        } else if ($column == 'status' && !in_array($value, $statuses)) {
            return $this->sendError([
                'message' => __('Value is not valid', 'fluent-crm')
            ]);
        }

        $companies = Company::whereIn('id', $companyIds)->get();

        foreach ($companies as $company) {

            if ($column == 'refetch_logo') {
                $newLogo = $this->getLogoWebsiteUrl($company->website);
                if ($newLogo) {
                    $company->logo = $newLogo;
                    $company->save();
                    return [
                        'message'      => __('Logo has been updated successfully', 'fluent-crm'),
                        'updated_logo' => $newLogo
                    ];
                }

                return $this->sendError([
                    'message' => __('Sorry, we could not find the logo from website. Please upload manually', 'fluent-crm')
                ]);
            }

            $oldValue = $company->{$column};
            if ($oldValue != $value) {
                $company->{$column} = $value;
                $company->save();
                if (in_array($column, ['type', 'status', 'owner_id'])) {
                    do_action('fluent_crm/company_' . $column . '_to_' . $value, $company, $oldValue);
                }
            }
        }

        return $this->sendSuccess([
            'message' => __('Company successfully updated', 'fluent-crm')
        ]);
    }

    public function delete(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        do_action('fluent_crm/before_company_delete', $company);
        $company->delete();
        do_action('fluent_crm/company_deleted', $id);

        return [
            'message' => __('Company has been deleted successfully', 'fluent-crm')
        ];
    }

    public function handleBulkActions(Request $request)
    {
        $actionName = sanitize_text_field($request->get('action_name', ''));

        $companyIds = array_map('intval', $request->get('company_ids', []));
        $companyIds = array_filter($companyIds);
        $lastId = $request->get('last_id', 0);

        if (!$companyIds) {
        

            $companyQuery = Company::orderBy('id', 'ASC')
                ->searchBy($request->getSafe('search', 'sanitize_text_field'));

            $inlineFilters = $request->get('company_query.inline_filters', []);

            if ($inlineFilters && is_array($inlineFilters)) {
                $inlineFilters = array_filter($inlineFilters);

                foreach ($inlineFilters as $key => $values) {
                    if (!is_array($values)) {
                        continue;
                    }
                    $values = array_map('sanitize_text_field', $values);

                    if ($key == 'company_categories') {
                        $companyQuery->whereIn('industry', $values);
                    } else if ($key == 'company_types') {
                        $companyQuery->whereIn('type', $values);
                    }
                }
            }
            $companyQuery = $companyQuery->limit(50)
                ->where('id', '>', $lastId);
        } else {
            $companyQuery = Company::whereIn('id', $companyIds);
        }

        $companies = $companyQuery->get();
        if ($companies->isEmpty()) {
            return [
                'is_completed'       => true,
                'completed_companies' => 0,
                'message'            => __('All companies have been processed', 'fluent-crm')
            ];
        }
        $companyIds = $companyQuery->pluck('id')->toArray();
        $lastCompanyId = end($companyIds);

        if ($actionName == 'delete_companies') {
            foreach ($companies as $company) {
                $id = $company->id;
                do_action('fluent_crm/before_company_delete', $company);
                $company->delete();
                do_action('fluent_crm/company_deleted', $id);
            }

            return $this->sendSuccess([
                'last_company_id'    => $lastCompanyId,
                'completed_companies' => count($companyIds),
                'message' => __('Selected Companies have been deleted permanently', 'fluent-crm'),
            ]);
        } elseif ($actionName == 'change_company_status') {
            $newStatus = sanitize_text_field($request->get('new_status', ''));
            if (!$newStatus) {
                return $this->sendError([
                    'message' => __('Please select status', 'fluent-crm')
                ]);
            }

            foreach ($companies as $company) {
                $oldStatus = $company->status;
                if ($oldStatus != $newStatus) {
                    $company->status = $newStatus;
                    $company->save();
                    do_action('fluent_crm/company_status_to_' . $newStatus, $company, $oldStatus);
                }
            }

            return [
                'last_company_id'    => $lastCompanyId,
                'completed_companies' => count($companyIds),
                'message' => __('Status has been changed for the selected companies', 'fluent-crm')
            ];
        } else if ($actionName == 'change_company_type') {
            $newType = sanitize_text_field($request->get('new_status', ''));
            if (!$newType) {
                return $this->sendError([
                    'message' => __('Please select new type', 'fluent-crm')
                ]);
            }
            foreach ($companies as $company) {
                $oldType = $company->type;
                if ($oldType != $newType) {
                    $company->type = $newType;
                    $company->save();
                    do_action('fluent_crm/company_type_to_' . $newType, $company, $oldType);
                }
            }

            return [
                'last_company_id'    => $lastCompanyId,
                'completed_companies' => count($companyIds),
                'message' => __('Company Type has been updated for the selected companies', 'fluent-crm')
            ];
        } else if ($actionName == 'change_company_category') {
            $newCategory = sanitize_text_field($request->get('new_status', ''));
            if (!$newCategory) {
                return $this->sendError([
                    'message' => __('Please select new category', 'fluent-crm')
                ]);
            }
            foreach ($companies as $company) {
                $oldCategory = $company->industry;
                if ($oldCategory != $newCategory) {
                    $company->industry = $newCategory;
                    $company->save();
                    do_action('fluent_crm/company_category_to_' . $newCategory, $company, $oldCategory);
                }
            }

            return [
                'last_company_id'    => $lastCompanyId,
                'completed_companies' => count($companyIds),
                'message' => __('Company Category has been updated for the selected companies', 'fluent-crm')
            ];
        }

        return [
            'last_company_id'    => $lastCompanyId,
            'completed_companies' => count($companyIds),
            'message' => __('Selected bulk action has been successfully completed', 'fluent-crm')
        ];
    }

    private function getSanitizedData($allData)
    {
        $rules = [
            'name' => 'required'
        ];

        if (Arr::get($allData, 'website')) {
            $allData['website'] = $this->makeHttpUrl($allData['website']);
            $rules['website'] = 'url';
        }

        if (Arr::get($allData, 'linkedin_url')) {
            $allData['linkedin_url'] = $this->makeHttpUrl($allData['linkedin_url']);
            $rules['linkedin_url'] = 'url';
        }

        if (Arr::get($allData, 'facebook_url')) {
            $allData['facebook_url'] = $this->makeHttpUrl($allData['facebook_url']);
            $rules['facebook_url'] = 'url';
        }

        if (Arr::get($allData, 'twitter_url')) {
            $allData['twitter_url'] = $this->makeHttpUrl($allData['twitter_url']);
            $rules['twitter_url'] = 'url';
        }

        $allData = $this->validate($allData, $rules);

        $data = Sanitize::company($allData);

        // Only allow real, user-editable company fields to be mass-assigned. System-managed
        // columns (hash, meta, created_at, updated_at) are deliberately excluded so a client
        // payload cannot overwrite them via createOrUpdate()->fill(). `id` is kept because
        // createOrUpdate() uses it to locate the record on update (it is guarded, never filled),
        // and `custom_values` is handled separately (merged into meta) by createOrUpdate().
        $allowedFields = [
            'id', 'name', 'owner_id', 'industry', 'type', 'email', 'phone',
            'address_line_1', 'address_line_2', 'postal_code', 'city', 'state', 'country',
            'timezone', 'employees_number', 'description', 'logo', 'website',
            'linkedin_url', 'facebook_url', 'twitter_url', 'date_of_start', 'custom_values',
        ];

        return Arr::only($data, $allowedFields);
    }

    private function makeHttpUrl($url)
    {
        if (!$url) {
            return $url;
        }
        $parsed_url = wp_parse_url($url);
        if (!$parsed_url || empty($parsed_url['scheme'])) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    private function getLogoWebsiteUrl($url)
    {
        if (!$url) {
            return NULL;
        }

        $url = $this->makeHttpUrl($url);
        $requestArgs = [
            'sslverify'           => false,
            'timeout'             => 5,
            'redirection'         => 2,
            'limit_response_size' => 1024 * 1024,
            'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3'
        ];
        $response = wp_safe_remote_get($url, $requestArgs);
        if (is_wp_error($response) || !$this->isSuccessfulRemoteResponse($response)) {
            return NULL;
        }

        $html = wp_remote_retrieve_body($response);
        if (!is_string($html) || strlen($html) > 1024 * 1024) {
            return NULL;
        }

        preg_match('/<link rel="apple-touch-icon"(?:.*?)href="([^"]+)"/i', $html, $matches);
        if (!isset($matches[1])) {
            preg_match('/<link rel="(?:shortcut|icon)"(?:.*?)href="([^"]+)"/i', $html, $matches);
        }
        if (empty($matches[1])) {
            return NULL;
        }
        $logoUrl = \WP_Http::make_absolute_url(html_entity_decode($matches[1], ENT_QUOTES), $url);

        $image = wp_safe_remote_get($logoUrl, array_merge($requestArgs, [
            'limit_response_size' => 5 * 1024 * 1024,
        ]));
        if (is_wp_error($image) || !$this->isSuccessfulRemoteResponse($image)) {
            return NULL;
        }

        $imageBody = wp_remote_retrieve_body($image);
        if (!is_string($imageBody) || $imageBody === '' || strlen($imageBody) > 5 * 1024 * 1024) {
            return NULL;
        }

        $imageInfo = @getimagesizefromstring($imageBody);
        if (!$imageInfo || empty($imageInfo[2])) {
            return NULL;
        }

        $extensions = [
            IMAGETYPE_GIF  => 'gif',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
        ];
        if (defined('IMAGETYPE_ICO')) {
            $extensions[IMAGETYPE_ICO] = 'ico';
        }
        $extension = Arr::get($extensions, $imageInfo[2]);
        if (!$extension) {
            return NULL;
        }

        $uploadDir = wp_upload_dir();
        if (!empty($uploadDir['error'])) {
            return NULL;
        }

        FileSystem::setCustomUploadDir([
            'baseurl' => $uploadDir['baseurl'],
            'basedir' => $uploadDir['basedir'],
        ]);

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        if (!$wp_filesystem) {
            return NULL;
        }

        $filename = 'company-logo-' . strtolower(wp_generate_uuid4()) . '.' . $extension;
        $filepath = $uploadDir['basedir'] . FLUENTCRM_UPLOAD_DIR . '/' . $filename;
        if (!$wp_filesystem->put_contents($filepath, $imageBody)) {
            wp_delete_file($filepath);
            return NULL;
        }

        return $uploadDir['baseurl'] . FLUENTCRM_UPLOAD_DIR . '/' . $filename;
    }

    private function isSuccessfulRemoteResponse(array $response)
    {
        $responseCode = wp_remote_retrieve_response_code($response);
        return $responseCode >= 200 && $responseCode < 300;
    }

    public function getNotes()
    {
        $companyId = $this->request->get('id');
        $search = $this->request->get('search');
        $includeId = intval($this->request->get('include_id', 0));

        $notes = CompanyNote::where('subscriber_id', $companyId);

        if (!empty($search)) {
            global $wpdb;
            $notes = $notes->where('title', 'LIKE', '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%');
        }

        $notes = $notes->orderBy('id', 'DESC')
            ->paginate();

        foreach ($notes as $note) {
            $note->added_by = $note->createdBy();
        }
        $fields['fields'] = Helper::getNoteSyncFields();

        $response = [
            'notes'  => $notes,
            'fields' => $fields
        ];

        if ($includeId) {
            $noteIds = (new Collection($notes->items()))->pluck('id')->toArray();
            if (!in_array($includeId, $noteIds)) {
                $includedNote = CompanyNote::where('id', $includeId)
                    ->where('subscriber_id', $companyId)
                    ->first();
                if ($includedNote) {
                    $includedNote->added_by = $includedNote->createdBy();
                    $response['included_note'] = $includedNote;
                }
            }
        }

        return $this->sendSuccess($response);
    }

    public function addNote(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $note = $this->validate($request->get('note'), [
            'title'       => 'required',
            'description' => 'required',
            'type'        => 'required',
            'created_at'  => 'nullable|date'
        ]);

        if (empty($note['created_at'])) {
            $note['created_at'] = current_time('mysql');
        }

        $note['subscriber_id'] = $id;

        $note = Sanitize::contactNote($note);

        // Only persist server-trusted fields on this endpoint (mirrors updateNote): authorship
        // is always the current user, and note metadata like parent_id/status is not
        // client-settable here. Prevents forging note authorship / re-threading via the payload.
        $note = Arr::only($note, ['subscriber_id', 'title', 'description', 'type', 'created_at']);
        $note['created_by'] = get_current_user_id();

        $subscriberNote = CompanyNote::create(wp_unslash($note));

        /**
         * Subscriber's Note Added
         *
         * @param SubscriberNote $subscriberNote Note Model.
         * @param Subscriber $subscriber Contact Model.
         * @param array $note Contact Note Data Array.
         * @since 1.0
         */
        do_action('fluent_crm/company_note_added', $subscriberNote, $company, $note);

        return $this->sendSuccess([
            'note'    => $subscriberNote,
            'message' => __('Note has been successfully added', 'fluent-crm')
        ]);
    }

    public function updateNote(Request $request, $id, $noteId)
    {
        $company = Company::findOrFail($id);

        $note = $this->validate($request->get('note'), [
            'title'       => 'required',
            'description' => 'required',
            'type'        => 'required',
            'created_at'  => 'sometimes|date'
        ]);

        $note = Arr::only(wp_unslash($note), ['title', 'description', 'type', 'created_at']);

        if (empty($note['created_at'])) {
            unset($note['created_at']);
        }

        $note = Sanitize::contactNote($note);

        // Scope the note to this company so a note belonging to another company cannot be
        // edited by pairing its id with a different (accessible) company route id.
        $companyNote = CompanyNote::where('id', $noteId)
            ->where('subscriber_id', $company->id)
            ->firstOrFail();
        $companyNote->fill($note);
        $companyNote->save();

        /**
         * Subscriber's Note Updated
         *
         * @param CompanyNote $companyNote Note Model.
         * @param Company $company Contact Model.
         * @param array $note Contact Note Data Array.
         * @since 1.0
         */
        do_action('fluent_crm/company_note_updated', $companyNote, $company, $note);

        return $this->sendSuccess([
            'note'    => $companyNote,
            'message' => __('Note successfully updated', 'fluent-crm')
        ]);
    }

    public function deleteNote($id, $noteId)
    {
        $company = Company::findOrFail($id);
        // Scope the delete to this company so a note belonging to another company cannot be
        // deleted by pairing its id with a different (accessible) company route id.
        $deleted = CompanyNote::where('id', $noteId)
            ->where('subscriber_id', $company->id)
            ->delete();

        if ($deleted) {
            /**
             * Subscriber's Note Delete
             *
             * @param int $noteId Note ID.
             * @param Company $company Company Model.
             * @since 1.0
             */
            do_action('fluent_crm/company_note_deleted', $noteId, $company);
        }

        return $this->sendSuccess([
            'message' => __('Note successfully deleted', 'fluent-crm')
        ]);
    }

    public function bulkDeleteNotes(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $noteIds = array_filter(array_map('intval', (array) $request->get('note_ids', [])));

        if (empty($noteIds)) {
            return $this->sendError([
                'message' => __('No note IDs provided', 'fluent-crm')
            ]);
        }

        if (count($noteIds) > 200) {
            return $this->sendError([
                'message' => __('Too many notes selected. Please delete 200 or fewer notes at a time.', 'fluent-crm')
            ]);
        }

        // Scope delete to this company so users cannot delete notes belonging to other companies.
        $deletableNoteIds = CompanyNote::where('subscriber_id', $company->id)
            ->whereIn('id', $noteIds)
            ->pluck('id')
            ->toArray();

        $deletedCount = 0;
        if ($deletableNoteIds) {
            $deletedCount = CompanyNote::whereIn('id', $deletableNoteIds)->delete();

            foreach ($deletableNoteIds as $deletedNoteId) {
                do_action('fluent_crm/company_note_deleted', $deletedNoteId, $company);
            }
        }

        return $this->sendSuccess([
            'message' => sprintf(
                /* translators: %d: number of deleted notes */
                _n('%d note deleted', '%d notes deleted', $deletedCount, 'fluent-crm'),
                $deletedCount
            )
        ]);
    }

    public function getCustomGlobalFields(CustomCompanyField $model)
    {
        return $this->sendSuccess(
            $model->getGlobalFields(
                $this->request->get('with', [])
            )
        );
    }

    public function saveCustomGlobalFields(CustomCompanyField $model)
    {
        $fields = $model->saveGlobalFields(
            Helper::parseArrayOrJson($this->request->get('fields'))
        );

        return $this->sendSuccess([
            'fields'  => $fields,
            'message' => __('Fields saved successfully!', 'fluent-crm')
        ]);
    }

    public function updateCustomFieldGroupName(CustomCompanyField $model)
    {
        $oldName = sanitize_text_field($this->request->get('old_name'));
        $newName = sanitize_text_field($this->request->get('new_name'));
        $updatedCustomFields = $model->updateGroupName($oldName, $newName);

        return $this->sendSuccess([
            'fields'  => $updatedCustomFields,
            'message' => __('Group name updated successfully!', 'fluent-crm')
        ]);
    }

    public function getCompanyExternalView(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        $sectionId = $request->get('section_provider');

        /**
         * Filter the company profile section content for a specific section ID.
         *
         * The dynamic portion of the hook name, `$sectionId`, refers to the section provider.
         *
         * Security: `content_html` is rendered as raw HTML in the admin UI (Vue v-html)
         * without client-side sanitization. Producers hooking this filter MUST escape any
         * user-authored data (e.g. via esc_html() / wp_kses_post()) before returning it, to
         * prevent stored XSS in the admin. Structural markup and intentional rich content
         * (styles, iframes, scripts) are allowed by design.
         *
         * @param array  An array with `heading` and `content_html` keys.
         * @param object $company The company object.
         */
        return apply_filters('fluent_crm/company_profile_section_' . $sectionId, [
            'heading'      => '',
            'content_html' => ''
        ], $company);
    }

    public function saveExternalViewData(Request $request, $companyId)
    {
        $company = Company::findOrFail($companyId);
        $sectionId = $request->get('section_provider');

        $response = apply_filters('fluent_crm/company_profile_section_save_' . $sectionId, '', $request->get('data', []), $company);

        if (!$response) {
            return $this->sendError([
                'message' => __('Handler could not be found.', 'fluent-crm')
            ]);
        }

        return $response;
    }
}
