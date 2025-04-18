<template>
    <div class="row">
        <div class="col-12 col-sm-7">
            <CCard class="h-100">
                <CCardHeader>
                    <strong>Total de tareas: {{totalTareas}}</strong>
                </CCardHeader>
                <CCardBody>
                    <div class="mb-4">
                        <div class="row">
                            <div class="col-12 col-sm-6">
                                <div>
                                    <label class="form-label">Fecha Inicial</label>
                                </div>
                                <input type="date" class="form-control" placeholder="Selecciona la fecha" v-model="fechaIni" @change="getGraph">
                            </div>
                            <div class="col-12 col-sm-6">
                                <div>
                                    <label class="form-label">Fecha Final</label>
                                </div>
                                <input type="date" class="form-control" placeholder="Selecciona la fecha" v-model="fechaFin" @change="getGraph">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 col-sm-12">
                            <highcharts :options="conteoTareas" class="mb-4"></highcharts>
                        </div>
                        <div class="col-12 col-sm-12">
                            <highcharts :options="porcentajeTareas" class="mb-4"></highcharts>
                        </div>
                    </div>
                </CCardBody>
            </CCard>
        </div>
        <div class="col-12 col-sm-5">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Iniciar tarea desde flujo</strong>
                </CCardHeader>
                <CCardBody class="panelProductoContainer">
                    <div v-for="(producto, indexP) in productos" class="panelProductoItem">
                        <div class="row">
                            <div class="col-12 col-sm-3">
                                <div class="text-start">
                                    <img v-if="producto.i !== null" :src="producto.i || ''" style="max-width: 80px"/>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <div>
                                    {{ producto.n }}
                                </div>
                            </div>
                            <div class="col-12 col-sm-3 text-end">
                                <div>
                                    <button class="btn btn-primary btn-sm"  v-tooltip="'Ver flujo'" @click="goToFlujo(producto.l)"><i class="fas fa-eye"></i></button>
                                </div>
                                <div class="mt-1">
                                    <button class="btn btn-outline-dark btn-sm"  v-tooltip="'Copiar enlace de flujo'" @click="copyLink(producto.t)"><i class="fas fa-link"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </CCardBody>
            </CCard>
        </div>
    </div>
</template>
<script>
import toolbox from "@/toolbox";
import 'form-wizard-vue3/dist/form-wizard-vue3.css'
import login from "@/views/pages/Login.vue";
import {CChart} from "@coreui/vue-chartjs";
import {vMaska} from "maska";
// Import FilePond
import vueFilePond from 'vue-filepond';
// Create FilePond component
import 'filepond/dist/filepond.min.css';
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css';
import FilePondPluginFileValidateType from "filepond-plugin-file-validate-type";
import FilePondPluginImagePreview from "filepond-plugin-image-preview";
import Button from "@/views/forms/form_elements/FormElementButton.vue";

const FilePond = vueFilePond();

// Import Swiper Vue.js components
import {Swiper, SwiperSlide} from 'swiper/vue';

import {Chart} from 'highcharts-vue';

// Import Swiper styles
import 'swiper/css';

import 'swiper/css/effect-fade';
import 'swiper/css/navigation';
import 'swiper/css/pagination';
import dayjs from "dayjs";
import {config} from "@/config";

export default {
    name: 'Tables',
    directives: {maska: vMaska},
    components: {
        Button,
        login,
        CChart,
        FilePond,
        Swiper,
        SwiperSlide,
        highcharts: Chart
    },
    data() {
        return {
            indexProductoSeleccionado: false,
            visibleVerticallyCenteredDemo: false,
            flujoSelected: {},
            salidaConfigNode: {},
            showWizzard: false,
            seccionesActivas: [],
            productoSelected: 0,
            seccionRaw: [],
            currentTabIndex: 0,
            camposSeccion: [],
            productos: [],
            nodeNexId: '',
            dataCotizacion: "",
            parseFormData: "",
            procesoSelected: {},
            nodosSiguientes: [],
            salidasSiguientes: [],
            procesosSiguientes: [],
            formulariosSiguientes: [],
            condicionesSiguientes: [],

            //##############Charts########
            fechaIni: dayjs().startOf('month').startOf('month').add(0, 'day').format('YYYY-MM-DD'),
            fechaFin: dayjs().endOf('month').endOf('month').format('YYYY-MM-DD'),
            labelsP: [],

            // graficos
            totalTareas: 0,
            porcentajeTareas: {},
            conteoTareas: {},
        };
    },
    mounted() {
        const self = this;
        toolbox.doAjax('POST', 'productos/get_panel', {
            rc: false
        }, function (response) {

            if (response.status) {
                self.productos = response.data;
                // Filtrar los productos que tienen la propiedad nombreProducto definida
                /*const productosConNombre = self.productos.filter((producto) => producto.nombreProducto !== null && producto.nombreProducto !== undefined);

                // Obtener los nombres de los productos en un array
                const nombresProductos = productosConNombre.map((producto) => producto.nombreProducto);

                self.labelsP = nombresProductos;*/
            }
        }, function (response) {
            self.msg = response.msg;
        })
        this.getGraph();
    },
    computed: {

        seccionesCumplenCondiciones() {
            // Filtrar las secciones que cumplen con las condiciones
            const seccionesValidas = this.seccionRaw;
            return seccionesValidas.filter((etapa, key) => this.cumpleCondiciones(etapa.condiciones));
        }
    },
    methods: {
        todasLasPropiedadesNoSonNulas(obj, propiedades) {
            return propiedades.every(prop => obj[prop] !== null);
        },
        onChangeCurrentTab(index) {
            this.currentTabIndex = index;
        },
        onTabBeforeChange() {
            if (this.currentTabIndex === 0) {
                console.log('First Tab');
            }
        },
        extractFields() {
            const camposFiltrados = this.flujoSelected
                .flatMap(node => node.formulario.secciones)
                .flatMap(seccion => seccion.campos)
                .filter(campo => campo.id && campo.nombre)
                .map(campo => ({id: campo.id, label: campo.nombre, value: campo.valor}));

            return camposFiltrados;
        },
        reemplazarTokensEnCotizacion(cotizacion) {
            const valores = this.extractFields();
            valores.forEach((valueObj) => {
                const token = `::${valueObj.id}::`;
                const valor = valueObj.value;

                cotizacion = cotizacion.replaceAll(token, valor);
            });
            return cotizacion;
        },
        extractConfigProcesoSalida(salidaConfig, respuesta) {
            salidaConfig.forEach((config) => {
                // Encontrar el campo con el nombre correspondiente en flujoSelected
                const campoEncontrado = this.flujoSelected
                    .flatMap(node => node.formulario.secciones)
                    .flatMap(seccion => seccion.campos)
                    .find(campo => campo.nombre === config.nombreCampo);

                const valorVariableExterna = this.buscarValorEnRespuesta(respuesta, config.variableExterna);

                if (campoEncontrado && valorVariableExterna !== undefined) {
                    campoEncontrado.valor = valorVariableExterna;
                }
            });

        },
        buscarValorEnRespuesta(respuesta, variableExterna) {
            if (respuesta.hasOwnProperty(variableExterna)) {
                return respuesta[variableExterna];
            }

            // Si la variable externa no está directamente en la respuesta, buscar en los objetos anidados
            for (const key in respuesta) {
                if (respuesta.hasOwnProperty(key) && typeof respuesta[key] === "object") {
                    const valorEncontrado = this.buscarValorEnRespuesta(respuesta[key], variableExterna);
                    if (valorEncontrado !== undefined) {
                        return valorEncontrado;
                    }
                }
            }

            // Si no se encuentra la variable externa, devolver undefined
            return undefined;
        },
        parseFormData(formSalida) {
            const regex = /::(.*?)::/g;
            let match;
            let formField = {};
            while ((match = regex.exec(formSalida)) !== null) {
                const token = match[0];
                const fieldName = match[1];

                if (!formField[fieldName]) {
                    this.datosPruebas[fieldName] = {
                        label: fieldName,
                        value: '',
                        name: fieldName,
                        type: 'text'
                    };
                    formField[fieldName] = {
                        label: fieldName,
                        value: '',
                        name: fieldName,
                        type: 'text'
                    }
                }


            }
            return formField;

        },
        filterInputNodes(index) {
            this.productoSelected = this.productos[index];
            this.flujoSelected = this.productos[index].flujo.nodes.filter(node => node.type === "input");
            this.visibleVerticallyCenteredDemo = true;
            this.indexProductoSeleccionado = index;
            this.filterSecciones();
            this.calculateNex(this.flujoSelected[0].id);
        },
        calculateNex(nodeId) {
            const productoActual = this.productos[this.indexProductoSeleccionado];
            const edgesConOrigen = productoActual.flujo.edges.filter(edge => edge.source === nodeId);
            const edgesDestino = edgesConOrigen.filter(source => source.target === source.target);
            console.log(edgesDestino);
            edgesDestino.forEach((valueObj) => {
                const nodo = productoActual.flujo.nodes.filter(node => node.id === valueObj.target);
                const salidasSiguientes = nodo[0];
                const procesoValido = this.todasLasPropiedadesNoSonNulas(salidasSiguientes.procesos, ["url", "type", "method"])

                if (salidasSiguientes.formulario.secciones.length > 0) {
                    this.formulariosSiguientes.push(salidasSiguientes.formulario);
                }
                if (salidasSiguientes) {
                    if (procesoValido) {
                        this.procesosSiguientes.push(salidasSiguientes);
                    }
                    if (salidasSiguientes.type === 'output') {
                        this.salidasSiguientes.push(salidasSiguientes);
                    }
                    if (salidasSiguientes.type === 'rombo') {
                        if (this.formulariosSiguientes.length === 0) {
                            this.condicionesSiguientes.push(salidasSiguientes);
                            this.calculateNex(salidasSiguientes.id);
                        }

                    }
                    this.nodosSiguientes.push(nodo[0]);
                }
            });
        },
        wizardCompleted() {
            this.procesosSiguientes.forEach((valueObj) => {
                const headers = this.reemplazarTokensEnCotizacion(valueObj.header, this.seccionRaw[this.currentTabIndex].campos);
                const urlR = this.reemplazarTokensEnCotizacion(valueObj.url, this.seccionRaw[this.currentTabIndex].campos);
                const dataToSend = this.reemplazarTokensEnCotizacion(valueObj.entrada, this.seccionRaw[this.currentTabIndex].campos);

                const headerString = headers.replace(/\n/g, '').replace(/\s+/g, ' ');
                const cotizacionString = dataToSend.replace(/\n/g, '').replace(/\s+/g, ' ');

                let headerParseado = {};
                let dataParseada = {};

                try {
                    dataParseada = JSON.parse(cotizacionString);
                } catch (error) {
                    dataParseada = {};
                }
                try {
                    headerParseado = JSON.parse(headerString);
                } catch (error) {
                    headerParseado = {}
                }

                toolbox.doAjax('POST', 'flujos/prueba', {
                        methodo: valueObj.method,
                        header: headerParseado,
                        url: urlR,
                        tipoRespuesta: (valueObj.tipoRecibido) ? 'xml' : 'json',
                        dataToSend: dataParseada,
                    },
                    function (response) {
                        //Reemplazo mis variables por mi proceso.
                        this.extractConfigProcesoSalida(valueObj.salida, response.data)
                    })
            });
        },
        goNext() {
            this.currentTabIndex++;
        },
        goBack() {
            this.currentTabIndex--;
        },

        filterSecciones() {
            const self = this;
            if (this.flujoSelected && self.flujoSelected.length > 0) {
                const formulario = self.flujoSelected[0].formulario;
                //console.log(self.flujoSelected);
                if (formulario && formulario.secciones) {

                    self.seccionRaw = formulario.secciones.map((seccion, index) => {
                        return seccion;
                    });
                    self.seccionesActivas = formulario.secciones.map((seccion, index) => {
                        const tmpSection = {
                            id: index,
                            title: seccion.nombre,
                            completada: false,
                            icon: 'https://bucket-elroble.s3.amazonaws.com/wp-content/uploads/2022/08/05091547/SA.png'
                        };
                        return tmpSection;
                    });
                }
            }

        },

        obtenerItemsPorCatalogo(nombreCatalogo) {
            const catalogo = this.productoSelected.extraData.planes.find(plan => plan.nombreCatalogo === nombreCatalogo);

            if (catalogo) {
                return catalogo.items;
            } else {
                return [];
            }
        },
        findCliente() {
            const self = this;
            toolbox.doAjax('GET', 'clientes-nit?cui=' + self.clienteDatos.cui + '&nit=' + self.clienteDatos.nit, {}, function (response) {

                if (typeof (response.data.NOMTER) !== 'undefined') {
                    self.clienteDatos = response.data;
                    self.clienteDatos.new = false;
                } else {
                    self.clienteDatos.NOMTER = '';
                    self.clienteDatos.APETER = '';
                    self.clienteDatos.APEMAT = '';
                    self.clienteDatos.EMAIL = '';
                    self.clienteDatos.CODCLI = '';
                    self.clienteDatos.INDCYG = 'N';
                    self.clienteDatos.CANTHIJOS = 0;
                    self.clienteDatos.new = true;
                }

            }, function (response) {
                alert(response.msg);
            });
        },
        calcularEdad(fechaNacimiento) {
            const fechaActual = new Date();
            const [anio, mes, dia] = fechaNacimiento.split('/');
            const fechaNac = new Date(parseInt(anio), parseInt(mes) - 1, parseInt(dia));

            let edad = fechaActual.getFullYear() - fechaNac.getFullYear();
            const mesActual = fechaActual.getMonth();
            const mesNac = fechaNac.getMonth();

            if (mesActual < mesNac || (mesActual === mesNac && fechaActual.getDate() < fechaNac.getDate())) {
                edad--;
            }

            return edad;
        },
        getPlanes() {
            const self = this;
            if (self.selectedProductModal.producto.codigoInterno) {
                toolbox.doAjax('GET', 'planes?plan=' + self.selectedProductModal.producto.codigoInterno, {}, function (response) {
                    // console.log(response);
                    self.listadoPlan = response.data;
                    $('#findClient').modal('hide');
                }, function (response) {
                    alert(response.msg);
                });
            }
        },
        hidModal(object) {
            $('#' + object).modal('hide');
        },
        truncarDescripcion(descripcion, limite) {
            if (!descripcion) return '';
            const palabras = descripcion.split(' ');
            const descripcionCortada = palabras.slice(0, limite).join(' ');
            return descripcionCortada;
        },

        getItems() {

            const self = this;
            toolbox.doAjax('GET', 'admin/formularios/list', {},
                function (response) {
                    //self.items = response.data;
                    self.items = toolbox.prepareForTable(response.data);
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        deleteItem(item) {
            const self = this;
            toolbox.confirm('¿Está seguro de eliminar?', function () {
                toolbox.doAjax('POST', 'admin/formulario/delete', {
                        id: item.id,
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'success');
                        self.getItems();
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'danger');
                    })
            })
        },


        cumpleCondiciones(condiciones) {
            // Verifica si todas las condiciones se cumplen
            return condiciones.every((condicion) => this.cumpleCondicion(condicion));
        },
        buscarValorEnCamposRecursivo(objeto, valorBuscado) {
            if (Array.isArray(objeto)) {
                for (const item of objeto) {
                    const resultado = this.buscarValorEnCamposRecursivo(item, valorBuscado);
                    if (resultado !== undefined) {
                        return resultado;
                    }
                }
                return undefined;
            } else if (typeof objeto === "object" && objeto !== null) {
                if (objeto.id === valorBuscado) {
                    return objeto;
                }

                for (const clave in objeto) {
                    const resultado = this.buscarValorEnCamposRecursivo(objeto[clave], valorBuscado);
                    if (resultado !== undefined) {
                        return resultado;
                    }
                }
                return undefined;
            }

            return undefined;
        },

        cumpleCondicion(condicion) {
            if (!condicion.campoId && condicion.value === null && condicion.is === null) {
                // Si falta algún campo, la condición no se cumple
                return true;
            }

            // Encuentra el campo correspondiente en la lista de campos (seccionesRaw)
            const campo = this.buscarValorEnCamposRecursivo(this.seccionRaw, condicion.campoId);

            if (!campo) {
                // Si el campo no se encuentra, la condición no se cumple
                return false;
            }


            // Compara el valor del campo con la condición utilizando el operador "is"
            switch (condicion.campoIs) {
                case ">":
                    return campo.valor > condicion.value;
                case "<":
                    return campo.valor < condicion.value;
                case "=":
                    return campo.valor === condicion.value;
                case "=>":
                    return campo.valor >= condicion.value;
                // Agrega otros casos según los operadores que desees admitir
                default:
                    return false;
            }
        },
        buscarCampoPorId(id) {
            // Combina todos los arrays en seccionesRaw en una sola lista
            const camposCombinados = this.seccionRaw;

            // Encuentra el campo por su id en la lista combinada
            return camposCombinados.find((campo) => campo.id === id);
        },
        editItem(item) {
            this.$router.push('/admin/formulario/edit/' + item.id);
        },

        getGraph() {

            const meses = {
                1: 'ene',
                2: 'feb',
                3: 'mar',
                4: 'abr',
                5: 'may',
                6: 'jun',
                7: 'jul',
                8: 'ago',
                9: 'sep',
                10: 'oct',
                11: 'nov',
                12: 'dic',
            }

            const colors = [];
            while (colors.length < 100) {
                do {
                    var color = Math.floor((Math.random()*1000000)+1);
                } while (colors.indexOf(color) >= 0);
                colors.push("#" + ("000000" + color.toString(16)).slice(-6));
            }

            const self = this;
            toolbox.doAjax('POST', 'productos/get-graph', {
                    fechaIni: self.fechaIni,
                    fechaFin: self.fechaFin,
                },
                function (response) {

                self.totalTareas = 0;

                    self.porcentajeTareas = {
                        chart: {
                            type: 'pie'
                        },
                        title: {
                            text: 'Porcentaje de tareas',
                            align: 'left'
                        },
                        plotOptions: {
                            series: {
                                allowPointSelect: true,
                                cursor: 'pointer',
                                dataLabels: [{
                                    enabled: true,
                                    distance: 20
                                }, {
                                    enabled: true,
                                    distance: -40,
                                    format: '{point.percentage:.1f}%',
                                    style: {
                                        fontSize: '0.5em',
                                        textOutline: 'none',
                                        opacity: 0.7
                                    },
                                    filter: {
                                        operator: '>',
                                        property: 'percentage',
                                        value: 10
                                    }
                                }]
                            }
                        },
                        tooltip: {
                            headerFormat: '<span style="font-size:11px">{series.name}</span><br>',
                            pointFormat: '<span style="color:{point.color}">{point.name}</span>: <b>{point.percentage:.2f}%</b><br/>',
                        },
                        series: [
                            {
                                name: 'Flujos',
                                colorByPoint: true,
                                data: [
                                ]
                            }
                        ],
                    };

                    self.conteoTareas = {
                        chart: {
                            type: 'bar'
                        },
                        title: {
                            text: 'Tareas por flujo',
                            align: 'left'
                        },
                        xAxis: {
                            categories: ['Flujos'],
                            title: {
                                text: null
                            },
                            gridLineWidth: 1,
                            lineWidth: 0
                        },
                        yAxis: {
                            min: 0,
                            title: {
                                text: 'Conteo de tareas',
                                align: 'high'
                            },
                            labels: {
                                overflow: 'justify'
                            },
                            gridLineWidth: 0
                        },
                        tooltip: {
                            valueSuffix: ' tareas'
                        },
                        plotOptions: {
                            bar: {
                                borderRadius: '10%',
                                dataLabels: {
                                    enabled: true
                                },
                                groupPadding: 0.1
                            }
                        },
                        legend: {
                            layout: 'horizontal',
                            align: 'center',
                            verticalAlign: 'bottom',
                            floating: false,
                            borderWidth: 0,
                            shadow: false
                        },
                        credits: {
                            enabled: false
                        },
                        series: []
                    };

                    Object.keys(response.data.p).map(function (a) {

                        self.totalTareas = self.totalTareas + response.data.p[a].c;

                        self.conteoTareas.series.push({
                            name: response.data.p[a].p,
                            data: [
                                response.data.p[a].c
                            ]
                        });

                        self.porcentajeTareas.series[0].data.push({
                            name: response.data.p[a].p,
                            y: response.data.p[a].c,
                        });
                    })
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        copyLink(producto) {
            const link = config.appUrl + '#/f/' + producto + '/view';
            toolbox.copyToClipboard(link);
        },
        goToFlujo(link) {
            this.showStartTaskModal = false;
            this.$router.push({ path: link }).then(() => { this.$router.go(0) })
        },
    }
}
</script>
