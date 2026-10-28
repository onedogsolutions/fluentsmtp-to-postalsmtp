import Vue from 'vue';
import lang from 'element-ui/lib/locale/lang/en';
import locale from 'element-ui/lib/locale';

import {
    Row,
    Col,
    Card,
    Form,
    Link,
    Alert,
    Table,
    Input,
    Radio,
    Button,
    Select,
    Option,
    Dialog,
    Popover,
    Loading,
    Tooltip,
    Checkbox,
    FormItem,
    RadioGroup,
    RadioButton,
    TableColumn,
    MessageBox,
    Notification
} from 'element-ui';

import App from './App.vue';

Vue.use(Row);
Vue.use(Col);
Vue.use(Card);
Vue.use(Form);
Vue.use(Link);
Vue.use(Alert);
Vue.use(Table);
Vue.use(Input);
Vue.use(Radio);
Vue.use(Button);
Vue.use(Select);
Vue.use(Option);
Vue.use(Dialog);
Vue.use(Popover);
Vue.use(Tooltip);
Vue.use(Checkbox);
Vue.use(FormItem);
Vue.use(RadioGroup);
Vue.use(RadioButton);
Vue.use(TableColumn);
Vue.use(Loading.directive);

Vue.prototype.$message = MessageBox.alert;
Vue.prototype.$notify = Notification;
Vue.prototype.$confirm = MessageBox.confirm;

locale.use(lang);

const appVars = Object.assign({
    assets_url: '',
    ajaxurl: '',
    nonce: '',
    plugin_url: '',
    fluentsmtp_url: '',
    settings_url: '',
    user_email: '',
    crypto_available: true,
    wp_config_const: '',
    defaults: {},
    connections: {},
    trans: {}
}, window.FluentSmtpPostal || {});

/**
 * appVars lives on the prototype, not in mixin data(): components read it
 * inside their own data() function, where instance data is not initialised
 * yet. A data() based mixin makes this.appVars undefined at that point and
 * the whole app fails to mount.
 */
Vue.prototype.appVars = appVars;

/**
 * Global helpers mirroring FluentSMTP's Vue prototype so the shared provider
 * partial (Postal.vue) uses the exact same $t and $post it would upstream.
 */
Vue.mixin({
    methods: {
        $t(string) {
            return (appVars.trans && appVars.trans[string]) || string;
        },
        $post(action, data = {}) {
            return window.jQuery.post(appVars.ajaxurl, Object.assign({
                action: 'fluentsmtp_postal_' + action,
                nonce: appVars.nonce
            }, data));
        }
    }
});

new Vue({
    el: '#fluentsmtp_postal_app',
    render: h => h(App)
});
