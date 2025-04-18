<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Programar descargas</strong>
                </CCardHeader>
                <CCardBody>
                    <div class="text-muted mb-5">
                        <i class="fas fa-info-circle me-2"></i> Esta ventana permite la descarga de documentos generados a través de flujos, se descargan masivamente a través de un rango de fechas en un archivo .zip, solo se toman en cuenta los formularios que posean información o más de un documento generado.
                    </div>
                    <div class="row">
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Seleccione el flujo</label>
                            </div>
                            <select class="form-control" v-model="productoId" @change="getCampos">
                                <option v-for="item in productos" :value="item.id">{{item.nombreProducto}}</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-8">
                            <div class="mb-3">
                                <label class="form-label">Segmentar por</label>
                            </div>
                            <multiselect
                                v-model="reporteSegmentacion"
                                :options="camposOptions"
                                :searchable="true"
                            />
                        </div>
                        <div class="col-12 col-sm-8">
                            <div class="mb-3">
                                <label class="form-label">Ordenar por</label>
                            </div>
                            <multiselect
                                v-model="reporteOrden"
                                :options="camposOptions"
                                :searchable="true"
                            />
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Fecha Inicial</label>
                            </div>
                            <input type="date" class="form-control" placeholder="Selecciona la fecha" v-model="fechaIni">
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Fecha Final</label>
                            </div>
                            <input type="date" class="form-control" placeholder="Selecciona la fecha" v-model="fechaFin">
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Tipo de descarga</label>
                            </div>
                            <select class="form-control" v-model="typeDownload">
                                <option value="gen">Solo archivos generados</option>
                                <option value="files">Solo archivos subidos</option>
                                <option value="gen_files">Archivos generados y subidos</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <div class="mt-4 text-end">
                            <button @click="programar" class="btn btn-primary me-4">Programar descarga</button>
                        </div>
                    </div>
                </CCardBody>
            </CCard>
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Descargas programadas</strong>
                    <button @click="getProgramados" class="btn btn-primary float-end"><i class="fas fa-sync"></i> Actualizar</button>
                </CCardHeader>
                <CCardBody>
                    <div class="text-muted text-end">Actualización automática en <span>{{timeSeconds}}</span>s</div>
                    <EasyDataTable :headers="headers" :items="programadas" :search-field="typeSearch" :search-value="searchValue" alternating >
                        <template #item-operation="item">
                            <div class="text-center">
                                <i class="fas fa-eye icon me-3" @click="getProgramadosDetalle(item.id)"></i>
                                <i v-if="parseInt(item.pg) === 100" class="fas fa-download icon me-3" @click="download(item)"></i>
                                <i class="fas fa-trash icon text-danger" @click="eliminar(item.id)"></i>
                            </div>
                        </template>
                    </EasyDataTable>
                </CCardBody>
                <div class="globalModal" v-if="showDetailModal">
                    <div class="globalModalContainer text-center p-5">
                        <div @click="showDetailModal = false" class="globalModalClose mt-3"><i class="fas fa-times-circle"></i></div>
                        <div>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">No. Tarea</th>
                                        <th scope="col">Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <tr v-for="(item, key) in detalleProgramado">
                                    <th scope="row">{{key+1}}</th>
                                    <td>
                                        {{item.cotizacionId}}
                                    </td>
                                    <td>
                                        <div v-if="item.isProcessed">
                                            <span class="text-danger" v-if="item.hasError">Error al procesar</span>
                                            <span class="text-success" v-if="!item.hasError">Procesado</span>
                                        </div>
                                        <div v-if="!item.isProcessed">Pendiente</div>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </CCard>
        </CCol>
    </CRow>
</template>

<script>
import toolbox from "@/toolbox";
import Multiselect from '@vueform/multiselect'
import Select from "@/views/forms/Select.vue";
import Button from "@/views/forms/form_elements/FormElementButton.vue";


export default {
    name: 'Tables',
    components: {Button, Select, Multiselect},
    data() {
        return {
            productos: {},
            productoId: 0,
            reporteOrden: '_none_',
            reporteSegmentacion: '_none_',
            camposOptions: [],
            typeDownload: 'gen',
            fechaIni: new Date().toISOString().slice(0,10),
            fechaFin: new Date().toISOString().slice(0,10),

            // descargas programadas
            typeSearch: 'nombre',
            searchValue: '',
            headers: [
                {text: "Nombre de flujo", value: "producto"},
                {text: "Tipo de descarga", value: "tipo"},
                {text: "Fecha inicial", value: "fechaIni"},
                {text: "Fecha final", value: "fechaFin"},
                {text: "Segmentación", value: "segmentacion"},
                {text: "Orden", value: "orden"},
                {text: "Total de tareas", value: "totalRows"},
                {text: "Estado", value: "estado"},
                {text: "Operación", value: "operation"},
            ],
            programadas: [],
            detalleProgramado: {},
            showDetailModal: false,

            // timer
            timeSeconds: 30,
        };
    },
    mounted() {
        const self = this;
        this.getProducts();
        this.getProgramados();

        // countdown
        /*const timerTmp = function () {

            if (self.timeSeconds < 1) {
                self.getProgramados();
            }
            else {
                self.timeSeconds--;
            }

            setTimeout(function () {
                timerTmp();
            }, 1000)
        }
        timerTmp();*/
    },
    methods: {
        getProgramados() {
            const self = this;
            toolbox.doAjax('POST', 'reportes/get-programmed-list', {},
                function (response) {
                    self.timeSeconds = 30;
                    self.programadas = toolbox.prepareForTable(response.data);
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        getProgramadosDetalle(id) {
            const self = this;
            this.detalleProgramado = {};
            toolbox.doAjax('POST', 'reportes/get-programmed-detail', {
                    id: id
                },
                function (response) {
                    self.showDetailModal = true;
                    self.detalleProgramado = response.data;
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        getProducts() {
            const self = this;
            toolbox.doAjax('GET', 'productos/get-list', {},
                function (response) {
                    self.productos = response.data;
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        getCampos() {
            const self = this;
            //console.log(this.flujos);
            toolbox.doAjax('POST', 'reportes/nodos/campos/copy', {
                    productos: [self.productoId]
                },
                function (response) {

                    self.camposOptions = [{
                        value: '_none_',
                        label: 'Sin campo seleccionado',
                    }];
                    Object.keys(response.data).map(function (a, b) {
                        self.camposOptions.push({
                            value: response.data[a].id,
                            label: response.data[a].label + ' (' + response.data[a].id + ')',
                        })
                    })
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                });
        },
        eliminar(id) {

            const self = this;

            toolbox.confirm('Se eliminará la descarga programada, ¿desea continuar?', function () {
                toolbox.doAjax('POST', 'reportes/docs/program/delete', {
                        id: id
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'success');
                        self.getProgramados();
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'danger');
                    })
            })
        },
        programar() {

            const self = this;

            toolbox.confirm('Se programará la generación de una descarga, ¿desea continuar?', function () {
                toolbox.doAjax('POST', 'reportes/docs/program', {
                        productoId: self.productoId,
                        reporteSegmentacion: self.reporteSegmentacion,
                        reporteOrden: self.reporteOrden,
                        typeDownload: self.typeDownload,
                        fechaIni: self.fechaIni,
                        fechaFin: self.fechaFin,
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'success');
                        self.getProgramados();
                        //window.open(response.data.url);
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'danger');
                    })
            })
        },
        download(item) {

            const self = this;

            toolbox.doAjax('POST', 'reportes/programmed/download', {
                    id: item.id,
                },
                function (response) {
                    toolbox.alert(response.msg, 'success');
                    window.open(response.data.url);
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
    }
}
</script>
