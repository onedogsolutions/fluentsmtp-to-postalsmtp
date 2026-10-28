<template>
    <!--
        Rendered by FluentSMTP's own ConnectionWizard through
        <component :is="connection.provider">, under its Sender Settings and above its
        Save button - which is FluentSMTP's, as are the validation errors passed in.

        The config provider only carries the z-index. FluentSMTP raises its own Element
        Plus overlays above wp-admin's chrome; this copy of Element Plus does not share
        that config, so without it a tooltip here would open underneath the admin bar.
    -->
    <el-config-provider :z-index="100000">
        <div>
            <h3 class="fs_config_title">{{ $t('Postal API Settings') }}</h3>

            <el-form-item>
                <label for="postal-server-url">
                    {{ $t('Postal Server URL') }}
                </label>
                <el-input
                    id="postal-server-url"
                    type="url"
                    v-model="connection.server_url"
                    placeholder="https://postal.example.com"
                />
                <error :error="errors.get('server_url')"/>
                <span class="small-help-text" style="display:block;margin-top:3px">
                    {{ $t('The base URL of your Postal server. Mail is sent to /api/v1/send/raw on this host over HTTPS.') }}
                </span>
            </el-form-item>

            <el-radio-group size="small" v-model="connection.key_store">
                <el-radio-button value="db">{{ $t('Store in Database') }}</el-radio-button>
                <el-radio-button value="wp_config">{{ $t('Store in wp-config.php') }}</el-radio-button>
            </el-radio-group>

            <template v-if="connection.key_store == 'db'">
                <el-form-item>
                    <label for="postal-key">
                        {{ $t('Server API Key') }}
                    </label>
                    <InputPassword
                        id="postal-key"
                        v-model="connection.api_key"
                    />
                    <error :error="errors.get('api_key')"/>
                    <span class="small-help-text" style="display:block;margin-top:3px">
                        {{ $t('Create this under your Postal server: Credentials → New Credential → type “API”.') }}
                    </span>
                </el-form-item>
            </template>

            <div class="fss_condesnippet_wrapper" v-else-if="connection.key_store == 'wp_config'">
                <el-form-item>
                    <label>{{ $t('Add the following line to your wp-config.php file:') }}</label>
                    <div class="code_snippet">
                        <textarea readonly style="width: 100%;">define( 'FLUENTSMTP_POSTAL_API_KEY', '********************' );</textarea>
                    </div>
                    <error :error="errors.get('api_key')"/>
                </el-form-item>
            </div>

            <el-form-item>
                <label style="width:100%">{{ $t('Verify SSL Certificate') }}</label>
                <el-checkbox true-value="yes" false-value="no" v-model="connection.verify_ssl">
                    {{ $t('Verify the Postal server TLS certificate (recommended).') }}
                    <el-tooltip effect="dark" placement="top-start">
                        <template #content>
                            <div style="max-width:300px">
                                {{ $t('Leave enabled for Let\'s Encrypt / Caddy certificates. Disable only for self-signed certificates on isolated lab servers.') }}
                            </div>
                        </template>
                        <el-icon><FsmIconInfo/></el-icon>
                    </el-tooltip>
                </el-checkbox>
                <p style="color: var(--fsm-warning-fg, #E6A23C); margin: 4px 0 0;" v-if="connection.verify_ssl === 'no'">
                    {{ $t('TLS certificate verification is disabled. The connection is still encrypted, but not authenticated.') }}
                </p>
            </el-form-item>
            <el-form-item>
                <label style="width:100%">{{ $t('Treat as Bounce') }}</label>
                <el-checkbox true-value="yes" false-value="no" v-model="connection.bounce">
                    {{ $t('Mark messages from this connection as bounces in Postal.') }}
                    <el-tooltip effect="dark" placement="top-start">
                        <template #content>
                            <div style="max-width:300px">
                                {{ $t('Most sites should leave this off. Enable only if this connection exclusively sends bounce notifications.') }}
                            </div>
                        </template>
                        <el-icon><FsmIconInfo/></el-icon>
                    </el-tooltip>
                </el-checkbox>
            </el-form-item>

            <span class="small-help-text" style="display:block;margin-top:5px">
                {{ $t('The sending domain must be verified on your Postal server, otherwise Postal rejects the message as an unauthenticated From address.') }}
            </span>
        </div>
    </el-config-provider>
</template>

<script>
import {
    ElCheckbox,
    ElConfigProvider,
    ElFormItem,
    ElIcon,
    ElInput,
    ElRadioButton,
    ElRadioGroup,
    ElTooltip
} from 'element-plus';
import InputPassword from '@/Pieces/InputPassword';
import Error from '@/Pieces/Error';

/*
 * Element Plus is imported per component because FluentSMTP compiles its own
 * copy in on demand and registers none of it globally. Its stylesheet is not
 * imported: FluentSMTP already ships the CSS for every component used here, and
 * a second copy would reset the theme variables its dark mode overrides.
 *
 * FsmIconInfo is not imported - it is one of the icons FluentSMTP registers on
 * its app, which is the app this component renders in.
 */
export default {
    name: 'Postal',
    props: ['connection', 'errors', 'provider', 'is_new'],
    components: {
        ElCheckbox,
            ElConfigProvider,
        ElFormItem,
        ElIcon,
        ElInput,
        ElRadioButton,
        ElRadioGroup,
            ElTooltip,
        InputPassword,
        Error
    },
    watch: {
        // A key typed before switching to wp-config would otherwise still be posted
        // and stored in the database.
        'connection.key_store'(value) {
            if (value === 'wp_config') {
                this.connection.api_key = '';
            }
        }
    }
};
</script>
