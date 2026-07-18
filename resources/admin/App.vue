<template>
    <div class="fluentsmtp_postal_wrap">
        <div class="fsp_header">
            <div class="fsp_header_brand">
                <img v-if="appVars.assets_url" :src="appVars.assets_url + 'images/postal.svg'" alt="Postal" class="fsp_logo"/>
                <div>
                    <h1>{{ $t('Postal for FluentSMTP') }}</h1>
                    <p class="fsp_sub">{{ $t('Deliver WordPress mail through the Postal HTTP API instead of SMTP.') }}</p>
                </div>
            </div>
            <el-button v-if="view === 'list'" type="primary" @click="addNew">
                {{ $t('Add Postal Connection') }}
            </el-button>
        </div>

        <el-card v-if="view === 'list'" shadow="never" class="fsp_card">
            <div v-if="connectionList.length">
                <el-table :data="connectionList" style="width: 100%">
                    <el-table-column :label="$t('From Email')" min-width="180">
                        <template slot-scope="scope">
                            <strong>{{ scope.row.provider_settings.sender_email }}</strong>
                            <div class="fsp_muted" v-if="scope.row.provider_settings.sender_name">
                                {{ scope.row.provider_settings.sender_name }}
                            </div>
                        </template>
                    </el-table-column>
                    <el-table-column :label="$t('Postal Server')" min-width="220">
                        <template slot-scope="scope">
                            {{ scope.row.provider_settings.server_url }}
                        </template>
                    </el-table-column>
                    <el-table-column :label="$t('Key Storage')" width="140">
                        <template slot-scope="scope">
                            <el-tag size="mini" type="info">
                                {{ scope.row.provider_settings.key_store === 'wp_config' ? $t('Config File') : $t('Database') }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column :label="$t('Actions')" width="240" align="right">
                        <template slot-scope="scope">
                            <el-button size="mini" @click="edit(scope.row)">{{ $t('Edit') }}</el-button>
                            <el-button size="mini" @click="openTestEmail(scope.row)">{{ $t('Send Test') }}</el-button>
                            <el-button size="mini" type="danger" plain @click="remove(scope.row)">{{ $t('Delete') }}</el-button>
                        </template>
                    </el-table-column>
                </el-table>
            </div>
            <div v-else class="fsp_empty">
                <p>{{ $t('No Postal connections yet.') }}</p>
                <el-button type="primary" @click="addNew">{{ $t('Add your first Postal connection') }}</el-button>
            </div>

            <p class="fsp_muted" style="margin-top: 20px">
                {{ $t('Saved connections appear in FluentSMTP and are used automatically for matching From addresses.') }}
                <a :href="appVars.fluentsmtp_url" target="_blank" rel="noopener">{{ $t('Open FluentSMTP') }}</a>
            </p>
        </el-card>

        <el-card v-else shadow="never" class="fsp_card">
            <postal-wizard
                :connection="current"
                :connection_key="currentKey"
                :is_new="isNew"
                :connections="connections"
                @saved="onSaved"
                @cancel="view = 'list'"
            />
        </el-card>

        <el-dialog :title="$t('Send Test Email')" :visible.sync="testDialog" width="420px" append-to-body>
            <p class="fsp_muted">{{ $t('Sends a real email through this Postal connection.') }}</p>
            <el-input v-model="testEmail" type="email" :placeholder="$t('Recipient email address')"/>
            <span slot="footer">
                <el-button @click="testDialog = false">{{ $t('Cancel') }}</el-button>
                <el-button type="primary" v-loading="sendingTest" @click="sendTestEmail">{{ $t('Send') }}</el-button>
            </span>
        </el-dialog>
    </div>
</template>

<script>
import PostalWizard from './Modules/Settings/PostalWizard.vue';

export default {
    name: 'App',
    components: {
        PostalWizard
    },
    data() {
        return {
            view: 'list',
            connections: Object.assign({}, this.appVars.connections || {}),
            current: null,
            currentKey: '',
            isNew: true,
            testDialog: false,
            testEmail: this.appVars.user_email || '',
            testKey: '',
            sendingTest: false
        };
    },
    computed: {
        connectionList() {
            return Object.keys(this.connections).map(key => this.connections[key]);
        }
    },
    methods: {
        defaultsClone() {
            return JSON.parse(JSON.stringify(this.appVars.defaults || {}));
        },
        addNew() {
            const connection = this.defaultsClone();
            if (!connection.sender_email) {
                connection.sender_email = this.appVars.user_email || '';
            }
            connection.has_api_key = false;
            this.current = connection;
            this.currentKey = '';
            this.isNew = true;
            this.view = 'edit';
        },
        edit(row) {
            this.current = JSON.parse(JSON.stringify(row.provider_settings));
            this.currentKey = row.connection_key;
            this.isNew = false;
            this.view = 'edit';
        },
        onSaved(data) {
            const rebuilt = {};
            Object.keys(data.connections || {}).forEach(key => {
                rebuilt[key] = data.connections[key];
            });
            this.connections = rebuilt;
            this.view = 'list';
        },
        remove(row) {
            this.$confirm(
                this.$t('Delete this Postal connection?'),
                this.$t('Confirm'),
                { type: 'warning' }
            ).then(() => {
                this.$post('delete', { connection_key: row.connection_key })
                    .then(response => {
                        this.$notify.success(response.data.message);
                        this.onSaved(response.data);
                    })
                    .fail(error => {
                        const data = (error && error.responseJSON && error.responseJSON.data) || {};
                        this.$notify.error({ title: this.$t('Error'), message: data.message || this.$t('Could not delete the connection.') });
                    });
            }).catch(() => {});
        },
        openTestEmail(row) {
            this.testKey = row.connection_key;
            this.testEmail = this.appVars.user_email || '';
            this.testDialog = true;
        },
        sendTestEmail() {
            this.sendingTest = true;
            this.$post('send_test_email', { connection_key: this.testKey, email: this.testEmail })
                .then(response => {
                    this.$notify.success(response.data.message);
                    this.testDialog = false;
                })
                .fail(error => {
                    const data = (error && error.responseJSON && error.responseJSON.data) || {};
                    this.$notify.error({ title: this.$t('Error'), message: data.message || this.$t('The test email could not be sent.') });
                })
                .always(() => {
                    this.sendingTest = false;
                });
        }
    }
};
</script>
