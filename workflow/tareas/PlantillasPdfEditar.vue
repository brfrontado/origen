<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Editar plantilla PDF</strong>
                </CCardHeader>
                <CCardBody>
                    <div class="row">
                        <div class="col-12 col-sm-6">
                            <div class="mb-3">
                                <label for="Nombre" class="form-label">Nombre</label>
                                <input type="text" class="form-control" id="Nombre" placeholder="Escribe aquí" v-model="nombre">
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="mb-3">
                                <label for="Nombre" class="form-label">Activo</label>
                                <select class="form-select" v-model="activo">
                                    <option value="1">Activo</option>
                                    <option value="0">Desactivado</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12">
                            <hr>
                            <div class="mb-3">
                                <h6 class="text-muted mb-3">Plantilla Docx</h6>
                                <CFormInput id="templateFile" type="file" class="form-control form-control-file"/>
                            </div>
                            <div v-if="urlShow !== ''">
                                <a class="btn btn-primary" :href="urlShow" target="_blank">Descargar plantilla</a>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="col-12 mt-4 text-end">
                            <button @click="$router.push('/admin/plantillas-pdf')" class="btn btn-danger me-2">Cancelar</button>
                            <button @click="guardar" class="btn btn-primary">Guardar</button>
                        </div>
                    </div>
                </CCardBody>
            </CCard>
        </CCol>
    </CRow>
</template>

<script>
import toolbox from "@/toolbox";
import Select from "@/views/forms/Select.vue";
import Multiselect from '@vueform/multiselect'
import Button from "@/views/forms/form_elements/FormElementButton.vue";

export default {
    name: 'Tables',
    components: {Button, Select, Multiselect},
    data() {
        return {
            id: 0,
            nombre: '',
            urltemplate: '',
            urlShow: '',
            activo: 0,

            // temporal en lo que el ajax de usuarios termina
        };
    },
    mounted() {
        this.id = (typeof this.$route.params.id !== 'undefined') ? parseInt(this.$route.params.id) : 0;
        //console.log(this.id);

        this.getData();
    },
    methods: {
        getData() {
            const self = this;

            if (this.id) {
                toolbox.doAjax('GET', 'tareas/get/pdf-template/' + this.id, {},
                    function (response) {
                        self.id = response.data.id;
                        self.nombre = response.data.nombre;
                        self.activo = response.data.activo;
                        self.urltemplate = response.data.urltemplate;
                        self.urlShow = response.data.urlShow;
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'danger');
                    });
            }
        },
        getUsers() {

            const self = this;
            toolbox.doAjax('GET', 'users/list', {},
                function (response) {
                    self.usuariosOptions = [];
                    Object.keys(response.data).map(function (a, b) {
                        self.usuariosOptions.push({
                            value: response.data[a].id,
                            label: response.data[a].name + " ("+response.data[a].email+")",
                        })
                    })
                    //console.log(self.usuariosOptions);
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        guardar() {

            const self = this;

            const fileTmp = document.getElementById('templateFile');

            // creo la data
            const formData = new FormData();
            formData.append('file', fileTmp.files[0]);
            formData.append('id', self.id);
            formData.append('nombre', self.nombre);
            formData.append('activo', self.activo);

            toolbox.doAjax('FILE', 'tareas/save/pdf-template', formData,
                function (response) {
                    if (self.id === 0 && typeof response.data.id !== 'undefined') {
                        window.location.href = '/#/admin/plantillas-pdf/' + response.data.id;
                        self.getData();
                    }

                    toolbox.alert(response.msg);
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })

        }
    }
}
</script>
