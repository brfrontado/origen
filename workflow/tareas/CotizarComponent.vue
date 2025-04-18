<template>
    <div :class="{'wizardContainer public': isPublic, 'wizardContainer privated': !isPublic}">
        <div class="stepsHeader" v-if="flujoActivo && flujoActivo.formulario && Object.keys(flujoActivo.formulario.secciones).length > 0">
            <Carousel ref="wizzardSteps" v-bind="configSteps">
                <template v-for="(etapa, index) in flujoActivo.formulario.secciones" :key="index">
                    <Slide v-if="etapa.show">
                        <div :class="(etapa.completada)?'carousel__item':((currentTabIndex === index)?'carousel__item active':'')">
                            <div class="step">
                                <!--<div class="bullet">{{index + 1}}</div>-->
                                <div class="text text-primary">{{ index + 1 }}. {{ etapa.nombre }}</div>
                            </div>
                        </div>
                    </Slide>
                </template>
            </Carousel>
            <div class="wizzardStepsNav">
                <button v-if="currentTabIndex > 0" style="left: 0" @click="cambiarSeccionSlide(currentTabIndex - 1)">
                    <i class="fas fa-chevron-left"></i></button>
                <button v-if="currentTabIndex < flujoActivo.formulario.secciones.length-1" style="right: 4%" @click="cambiarSeccionSlide(currentTabIndex + 1)">
                    <i class="fas fa-chevron-right"></i></button>
            </div>
            <!--<div class="stepsHeaderLine">
                <div class="step">
                    <div class="bullet">1</div>
                    Step
                </div>
                <div class="step">
                    <div class="bullet">2</div>
                    Step
                </div>
                <div class="step">
                    <div class="bullet">3</div>
                    Step
                </div>
                <div class="step">
                    <div class="bullet">4</div>
                    Step
                </div>
            </div>-->
        </div>
        <div class="wizardBanners" v-if="typeof producto.bannerSP !== 'undefined' && typeof producto.bannerSM !== 'undefined' && producto.bannerSP">
            <div class="wizardCard">
                <div class="bannerSupP d-none d-sm-block">
                    <img :src="producto.bannerSP"/>
                </div>
                <div class="bannerSupM d-block d-sm-none">
                    <img :src="producto.bannerSM"/>
                </div>
            </div>
        </div>
        <div class="wizardLogo wizardCard" v-if="!producto.logoDes && typeof producto.extraData !== 'undefined' && typeof producto.imagenData !== 'undefined' && producto.imagenData">
            <div>
                <img :src="producto.imagenData || ''" style="max-height: 60px;"/>
            </div>
        </div>
        <div class="wizardHeaderContainer">
            <div class="wizardHeader wizardCard">
                <div class="row miniHeader m-0" v-if="isCoti()">
                    <div class="col-12 p-0">
                        <div>
                            <h5>
                                {{ producto.nombreProducto || 'Flujo sin nombre' }} - No. {{ cotizacion.no }}
                            </h5>
                        </div>
                        <div v-if="typeof cotizacion.ed !== 'undefined' && cotizacion.ed !== ''">
                            <div class="custom-formTitle" v-if="flujoActivo.label">{{ flujoActivo.nodoName }}</div>
                            <div class="text-muted">
                                <i class="fas fa-user me-2"></i>{{ cotizacion.ed }}
                            </div>
                        </div>
                        <div>
                            <span style="text-transform: capitalize;" class="text-info" v-if="estadoCotizacion !== ''">Estado: {{ estadoCotizacion }}</span>
                        </div>
                    </div>
                </div>
                <div class="subheader">
                    <button v-if="!isPublic" class="btn btn-light float-end btn-sm" @click="$router.push('/task-list')">
                        <span><i class="fas fa-arrow-alt-circle-left"></i></span>
                        <span class="d-none d-sm-inline">Ir a listado de formularios</span>
                    </button>
                    <button v-if="!isPublic && isCoti()" class="btn btn-light float-end btn-sm" @click="rerunstep">
                        <span><i class="fa-solid fa-arrow-rotate-left"></i></span>
                        <span class="d-none d-sm-inline">Re-procesar</span>
                    </button>
                    <button v-if="!isPublic" class="btn btn-light float-end btn-sm" @click="copyLink('/#/f/'+pToken+'/'+cToken)">
                        <span><i class="fa-solid fa-copy"></i></span>
                        <span class="d-none d-sm-inline">Link público</span>
                    </button>
                    <button v-if="flujoActivo.cmT && (flujoActivo.cmT === 'm' || (flujoActivo.cmT === 'p' && !isPublic))" class="btn btn-light btn-sm float-end" @click="getComentarios">
                        <i class="fas fa-comment"></i>
                        <span class="d-none d-sm-inline">Comentarios</span>
                    </button>
                    <template v-if="!isPublic">
                        <button v-if="(typeof flujoActivo.ocrOptions !== 'undefined')" class="btn btn-success float-end btn-sm" @click="previewOcrEnabled = true">
                            <span><i class="fas fa-eye"></i></span>
                            <span class="d-none d-sm-inline">Previsualizar OCR</span>
                        </button>
                        <button v-if="isCoti()" class="btn btn-success float-end btn-sm" @click="verProgresion">
                            <span><i class="fas fa-list-check"></i></span>
                            <span class="d-none d-sm-inline">Ver progresión</span>
                        </button>
                        <div class="globalModal" v-if="showProgresion">
                            <div class="globalModalContainer p-5">
                                <div @click="showProgresion = false" class="globalModalClose mt-3">
                                    <i class="fas fa-times-circle"></i></div>
                                <div>
                                    <h5>Progresión de tarea global</h5>
                                </div>
                                <hr>
                                <div class="mt-5">
                                    <h5 class="text-primary">Porcentaje completado {{ progresion.percent }}%</h5>
                                    <CProgress
                                        class="mt-2"
                                        color="success"
                                        thin
                                        :precision="1"
                                        :value="progresion.percent"
                                    />
                                    <div class="text-muted mt-3">
                                        Total de campos: {{ progresion.total }}
                                    </div>
                                </div>
                                <div class="mt-5">
                                    <h5>Progresión por secciones</h5>
                                </div>
                                <hr>
                                <div class="row">
                                    <template class="mt-5" v-for="item in progresion.nodos">
                                        <div class="col-12 col-sm-4 mb-4" v-for="seccion in item.secciones">
                                            <h5 class="text-primary">{{ seccion.nombre }}</h5>
                                            <CProgress
                                                class="mt-2"
                                                color="success"
                                                thin
                                                :precision="1"
                                                :value="seccion.percent"
                                            />
                                            <div class="text-muted mt-3">
                                                Total de campos: {{ seccion.total }}
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <div v-if="!isCoti()">
                    <div class="text-center">
                        <div>
                            <div class="wizardHeaderStart">
                                <h5 class="m-0">{{ producto.nombreProducto || 'Flujo sin nombre' }}</h5>
                            </div>
                            <div class="my-4" v-if="producto.descripcion !== ''">
                                <div v-html="producto.descripcion"></div>
                            </div>
                        </div>
                        <hr>
                        <button class="btn btn-primary mt-4 mb-5" @click="iniciarCotizacion">
                            <i class="fas fa-arrow-circle-right me-2"></i> Iniciar formulario
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="wizardBody wizardCard" v-if="isCoti()">
            <div>
                <div v-if="!showCotizacion">
                    <template v-if="isCoti()">
                        <div>
                            <h5 class="text-center">{{ showCotizacionDesc }}</h5>
                        </div>
                        <div class="text-center py-4" v-if="isPublic">
                            <button @click="goToProduct" class="btn btn-primary">Volver al inicio</button>
                        </div>
                        <div class="text-center py-5" v-if="estadoCotizacion === 'expirada'">
                            <button @click="revivirCotizacion" class="btn btn-primary">
                                <i class="fas fa-check-circle me-2"></i>Revivir cotización
                            </button>
                        </div>
                    </template>
                </div>
                <template v-else>
                    <div>
                        <!--<CCardHeader>
                            {{ flujoActivo.formulario.secciones[currentTabIndex].nombre }}
                        </CCardHeader>-->
                        <div>
                            <div v-if="flujoActivo.typeObject !== 'start' && flujoActivo.typeObject !== 'input' && flujoActivo.typeObject !== 'output' && flujoActivo.typeObject !== 'ocr'" class="text-muted text-center">
                                El formulario se encuentra en una etapa sin visualización, podrá continuarse cuando cambie de etapa.
                            </div>
                            <div v-else>
                                <div class="row">
                                    <div :class="`col-${(flujoActivo.typeObject !== 'ocr' || !showPreviewOcr)? 12 : 6}`">
                                        <div class="row">
                                            <!--<div class="col-1 col-sm-3" v-if="Object.keys(flujoActivo.formulario.secciones).length > 0">
                                                <ul class="progress-indicator nocenter stacked">
                                                    <template v-for="(etapa, index) in flujoActivo.formulario.secciones" :key="index">
                                                        <li :class="(etapa.completada)?'completed':((currentTabIndex === index)?'active':'')" @click="cambiarSeccion(index)" v-if="etapa.show">
                                                            <span class="bubble"></span>
                                                            <h6
                                                                class="stacked-text d-none d-sm-block">
                                                                {{ etapa.nombre }}
                                                            </h6>
                                                        </li>
                                                    </template>
                                                </ul>
                                                <hr class="progress-indicator-separator">
                                            </div>-->
                                            <div class="col-12" v-if="typeof (flujoActivo.formulario.secciones[currentTabIndex]) !=='undefined' ">
                                                <div v-if="flujoActivo.formulario.secciones[currentTabIndex].instrucciones">
                                                    <div class="bg-light rounded p-3 mb-4">
                                                        <h6 class="fw-bold">Instrucciones:</h6>
                                                        {{ flujoActivo.formulario.secciones[currentTabIndex].instrucciones }}
                                                    </div>
                                                </div>
                                                <div v-if="flujoActivo.formulario.secciones[currentTabIndex].show && ocrFieldsExists" class="ocrFieldsContainer">
                                                    <div class="row mb-4">
                                                        <div class="col-3 col-sm-1">
                                                            <img src="../../../assets/images/ocr_lupa.png" style="max-width: 50px; margin: auto;">
                                                        </div>
                                                        <div class="col-9 col-sm-11">
                                                            <h5>Campos con detección automática de datos</h5>
                                                            <div class="text-secondary mt-2">
                                                                Los siguientes campos soportan la detección automática de datos, sube tus documentos para iniciar el proceso.
                                                            </div>
                                                            <div class="verifyDataModal" v-if="verifyModal">
                                                                <div class="verifyDataModalContainer">
                                                                    <h2>Escaneo completado</h2>
                                                                    <img src="../../../assets/images/verifydata.png" class="verifyImage">
                                                                    <div>
                                                                        Por favor, verifica los datos detectados antes de continuar a la siguiente etapa
                                                                    </div>
                                                                    <div class="mt-5">
                                                                        <button class="btn btn-primary" @click="verifyModal = false">Aceptar</button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="mb-4 row">
                                                        <template v-for="(input, keyInput) in flujoActivo.formulario.secciones[currentTabIndex].campos">
                                                            <template v-if="typeof camposCumplidores[input.id] !== 'undefined' && camposCumplidores[input.id]">
                                                                <template v-if="typeof(input) !=='undefined' && input.activo">
                                                                    <div v-if="(input.tipoCampo !== null && (input.tipoCampo === 'file' ||  input.tipoCampo === 'fileER')) && (input.mascara === '' || input.mascara === null) && (input.ocr && input.ocrTPl)"   :class="'col-' + ((input.layoutSizeMobile) ? input.layoutSizeMobile : '12') + ' col-sm-' + ((input.layoutSizePc) ? input.layoutSizePc : '4') + ' mb-3' + (!input.visible ? ' d-none' : '')">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <div :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}">
                                                                            <file-pond type="file"
                                                                                       :key="'filepondInput_'+input.id"
                                                                                       class="filepond"
                                                                                       :name="input.id"
                                                                                       label-idle="Click para seleccionar o arrastra tus archivos acá"
                                                                                       v-bind:allow-multiple="true"
                                                                                       credits="false"
                                                                                       data-allow-reorder="true"
                                                                                       data-max-file-size="150MB"
                                                                                       data-max-files="10"
                                                                                       :allowImagePreview="isPublic"
                                                                                       :disabled="input.deshabilitado"
                                                                                       :server="{
                                                                            process: (fieldName, file, metadata, load, error, progress, abort) => {
                                                                                handleUpload(file, input.id, load, error, progress, true);
                                                                            },
                                                                            revert: (uniqueFileId, load, error) => {
                                                                                handleRevert(uniqueFileId, load, error);
                                                                            },

                                                                        }"
                                                                                       ref="filepondInput">
                                                                            </file-pond>
                                                                            <div class="text-center mt-2" v-if="typeof archivosTemporales[input.id] !== 'undefined' && archivosTemporales[input.id]">
                                                                                <a class="aHover" :href="archivosTemporales[input.id]" target="_blank">Ver archivo cargado</a>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </template>
                                                            </template>
                                                        </template>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <template v-if="flujoActivo.formulario.secciones[currentTabIndex].show">
                                                        <template v-for="(input, keyInput) in flujoActivo.formulario.secciones[currentTabIndex].campos">
                                                            <template v-if="typeof camposCumplidores[input.id] !== 'undefined' && camposCumplidores[input.id]">

                                                                <div v-if="typeof(input) !=='undefined' && input.activo" :class="'col-' + ((input.layoutSizeMobile) ? input.layoutSizeMobile : '12') + ' col-sm-' + ((input.layoutSizePc) ? input.layoutSizePc : '4') + ' mb-4' + (!input.visible ? ' d-none' : '')">
                                                                    <div v-if="input.tipoCampo === 'title'">
                                                                        <h5 class="text-primary fw-bold mb-3">{{ input.nombre }}</h5>
                                                                    </div>
                                                                    <div v-if="input.tipoCampo === 'subtitle'">
                                                                        <h6 class="text-primary fw-bold mb-3">{{ input.nombre }}</h6>
                                                                    </div>
                                                                    <div v-if="input.tipoCampo === 'txtlabel'">
                                                                        <div v-html="input.valor"></div>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo === null || input.tipoCampo === 'text') && (input.mascara !== '' || input.mascara !== null)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <input type="text" :class="{'form-control': !input.requeridoError, 'form-control requiredInput': input.requeridoError}"
                                                                               :readonly="input.readonly || input.valorCalculado"
                                                                               :label="input.nombre"
                                                                               :aria-label="input.nombre"
                                                                               v-model="input.valor"
                                                                               :id="input.id"
                                                                               v-maska
                                                                               :data-maska="input.mascara"
                                                                               :data-maska-tokens="input.tokenMask"
                                                                               :disabled="input.deshabilitado"
                                                                               :placeholder="input.ph"
                                                                               :minlength="input.longitudMin"
                                                                               :maxlength="input.longitudMax"
                                                                               @change="campoCumpleCondiciones(input)"
                                                                               @blur="saveWhenOnBlur(input)">
                                                                        <small class="text-muted">{{ input.desc }}</small>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'textArea') && (input.mascara !== '' || input.mascara !== null)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <textarea type="text" :class="{'form-control': !input.requeridoError, 'form-control requiredInput': input.requeridoError}"
                                                                                  :readonly="input.readonly || input.valorCalculado"
                                                                                  :label="input.nombre"
                                                                                  :aria-label="input.nombre"
                                                                                  v-model="input.valor"
                                                                                  :id="input.id"
                                                                                  v-maska
                                                                                  :data-maska="input.mascara"
                                                                                  :data-maska-tokens="input.tokenMask"
                                                                                  :disabled="input.deshabilitado"
                                                                                  :placeholder="input.ph"
                                                                                  :minlength="input.longitudMin"
                                                                                  :maxlength="input.longitudMax"
                                                                                  @change="campoCumpleCondiciones(input)"
                                                                                  @blur="saveWhenOnBlur(input)"
                                                                        >
                                                                        </textarea>
                                                                        <small class="text-muted">{{ input.desc }}</small>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'date') && (input.mascara !== '' || input.mascara !== null)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <input type="date" :class="{'form-control': !input.requeridoError, 'form-control requiredInput': input.requeridoError}"
                                                                               :readonly="input.readonly"
                                                                               :label="input.nombre"
                                                                               :aria-label="input.nombre"
                                                                               v-model="input.valor"
                                                                               :id="input.id"
                                                                               v-maska
                                                                               :data-maska="input.mascara"
                                                                               :data-maska-tokens="input.tokenMask"
                                                                               :disabled="input.deshabilitado"
                                                                               :placeholder="input.ph"
                                                                               @change="campoCumpleCondiciones(input)"
                                                                               @blur="saveWhenOnBlur(input)"
                                                                        >
                                                                        <small class="text-muted">{{ input.desc }}</small>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'number') && (input.mascara !== '' || input.mascara !== null)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <input type="text" :class="{'form-control': !input.requeridoError, 'form-control requiredInput': input.requeridoError}"
                                                                               :readonly="input.readonly"
                                                                               :aria-label="input.nombre"
                                                                               v-model="input.valor"
                                                                               :id="input.id"
                                                                               pattern="\d*"
                                                                               v-maska
                                                                               :data-maska="input.mascara"
                                                                               :data-maska-tokens="input.tokenMask"
                                                                               :disabled="input.deshabilitado"
                                                                               :placeholder="input.ph"
                                                                               :minlength="input.longitudMin"
                                                                               :maxlength="input.longitudMax"
                                                                               @change="campoCumpleCondiciones(input)"
                                                                               @blur="saveWhenOnBlur(input)"
                                                                        >
                                                                        <small class="text-muted">{{ input.desc }}</small>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'numslider') && (input.mascara !== '' || input.mascara !== null)">
                                                                        <div :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}">
                                                                            <label class="mb-2" :for="input.id"><span v-if="input.requerido">*</span>{{ input.nombre }}</label>
                                                                            <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                            <Slider v-model="input.valor" :min="parseInt(input.longitudMin)" :max="parseInt(input.longitudMax)" :showTooltip="'focus'"/>
                                                                        </div>
                                                                        <small class="text-muted">{{ input.desc }}</small>
                                                                    </div>
                                                                    <div v-if="input.tipoCampo !== null && input.tipoCampo === 'select' && (input.mascara === '' || input.mascara === null)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <!--                                                                <select :class="{'form-select': !input.requeridoError, 'form-select requiredInput': input.requeridoError}" v-model="input.valor" aria-label=".form-select-lg example" :readonly="input.readonly" :disabled="input.deshabilitado" @change="campoCumpleCondiciones(input)">
                                                                                                                                            <template v-if="typeof catalogos[input.id] !== 'undefined'">
                                                                                                                                                <option v-if="typeof (input.catalogoId) !== 'undefined'" v-for="(item , index) in catalogos[input.id]" :value="item[input.catalogoValue]" :key="index">
                                                                                                                                                    {{ item[input.catalogoLabel] }}
                                                                                                                                                </option>
                                                                                                                                            </template>
                                                                                                                                        </select>-->
                                                                        <select
                                                                            v-if="input.fillBy === 'con'"
                                                                            :class="{'form-select': !input.requeridoError, 'form-select requiredInput': input.requeridoError}"
                                                                            v-model="input.valor"
                                                                            aria-label=".form-select-lg example"
                                                                            :readonly="input.readonly"
                                                                            :disabled="input.deshabilitado"
                                                                            @select="campoCumpleCondiciones(input)"
                                                                            @blur="saveWhenOnBlur(input)"
                                                                        >
                                                                            <option v-for="(item , index) in obtenerItemsPorCatalogoMS('conn_' + input.fillByCon, input.conLabel, input.conValue)" :value="item.value" :key="index">
                                                                                {{ item.label }}
                                                                            </option>
                                                                        </select>
                                                                        <multiselect v-else :options="obtenerItemsPorCatalogoMS(input.id, input.catalogoLabel, input.catalogoValue)" v-model="input.valor" :searchable="true" :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}" :readonly="input.readonly" :disabled="input.deshabilitado" @select="async () => {await campoCumpleCondiciones(input); saveWhenOnBlur(input)}"></multiselect>
                                                                        <small class="text-muted">{{ input.desc }}</small>
                                                                    </div>
                                                                    <div v-if="input.tipoCampo !== null && input.tipoCampo === 'multiselect' && (input.mascara === '' || input.mascara === null)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <div :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}">
                                                                            <multiselect
                                                                                :options="obtenerItemsPorCatalogo(input.id, input.catalogoLabel, input.catalogoValue)"
                                                                                :searchable="true"
                                                                                :mode="'tags'"
                                                                                :label="input.catalogoLabel"
                                                                                :value-prop="input.catalogoValue"
                                                                                :disabled="input.deshabilitado"
                                                                                v-model="input.valor"
                                                                                :max="input.max"
                                                                                :allow-empty="!input.requerido"
                                                                                :min="input.min"
                                                                                @change="saveValueMultiSelect(input)"
                                                                            />
                                                                        </div>
                                                                        <small class="text-muted">{{ input.desc }}</small>
                                                                    </div>
                                                                    <div v-if="input.tipoCampo !== null && input.tipoCampo === 'checkbox' && (input.mascara === '' || input.mascara === null)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <div :class="{'form-check': !input.requeridoError, 'form-check requiredInput': input.requeridoError}" v-if="typeof (input.catalogoId) !== 'undefined'" v-for="(item, indexOption) in obtenerItemsPorCatalogoMS(input.id, input.catalogoLabel, input.catalogoValue)">
                                                                            <input class="form-check-input" :name="input.id" :checked="!!input.valor && input.valor.includes(item.value)" :value="item.value" type="checkbox" :id="input.id+'_'+item[input.catalogoLabel]+'_'+indexOption" :disabled="input.deshabilitado" @change="selectedMultiValue(input, item.value, keyInput, currentTabIndex)"
                                                                                   @blur="saveWhenOnBlur(input)">
                                                                            <label class="form-check-label" :for="input.id+'_'+item[input.catalogoLabel]+'_'+indexOption"> {{ item.label }}</label>
                                                                        </div>
                                                                    </div>
                                                                    <div v-if="input.tipoCampo !== null && input.tipoCampo === 'option' && (input.mascara === '' || input.mascara === null)">
                                                                        <div>
                                                                            <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        </div>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <span :class="{'form-check': !input.requeridoError, 'form-check requiredInput': input.requeridoError}" v-if="typeof (input.catalogoId) !== 'undefined'" v-for="item in obtenerItemsPorCatalogo(input.id)">
                                                                            <input class="form-check-input" :name="input.id" v-model="input.valor" :value="item[input.catalogoValue]" type="radio" :id="input.id+'_'+item[input.catalogoLabel]" :disabled="input.deshabilitado" @change="campoCumpleCondiciones(input)"
                                                                                   @blur="saveWhenOnBlur(input)">
                                                                            <label class="form-check-label" :for="input.id+'_'+item[input.catalogoLabel]">
                                                                                {{ item[input.catalogoLabel] }}
                                                                            </label>
                                                                        </span>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'range') && (input.mascara === '' || input.mascara === null)">
                                                                        <label :for="input.id"><span v-if="input.requerido">*</span>{{ input.nombre }} {{ input.valor }}</label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <div class="input-group">
                                                                            <input type="range"
                                                                                   class="form-range"
                                                                                   :id="input.id"
                                                                                   :min="input.longitudMin"
                                                                                   :max="input.longitudMax"
                                                                                   step="1"
                                                                                   v-model="input.valor"
                                                                                   :disabled="input.deshabilitado"
                                                                                   @change="campoCumpleCondiciones(input)"
                                                                                   @blur="saveWhenOnBlur(input)"
                                                                            >
                                                                        </div>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && (input.tipoCampo === 'file' ||  input.tipoCampo === 'fileER')) && (input.mascara === '' || input.mascara === null) && (!input.ocr)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <div :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}">
                                                                            <file-pond type="file"
                                                                                       :key="'filepondInput_'+input.id"
                                                                                       class="filepond"
                                                                                       :name="input.id"
                                                                                       label-idle="Click para seleccionar o arrastra tus archivos acá"
                                                                                       v-bind:allow-multiple="true"
                                                                                       credits="false"
                                                                                       data-allow-reorder="true"
                                                                                       data-max-file-size="150MB"
                                                                                       data-max-files="10"
                                                                                       :allowImagePreview="isPublic"
                                                                                       :disabled="input.deshabilitado"
                                                                                       :server="{
                                                                            process: (fieldName, file, metadata, load, error, progress, abort) => {
                                                                                handleUpload(file, input.id, load, error, progress);
                                                                            },
                                                                            revert: (uniqueFileId, load, error) => {
                                                                                handleRevert(uniqueFileId, load, error);
                                                                            },

                                                                        }"
                                                                                       ref="filepondInput">
                                                                            </file-pond>
                                                                            <div class="text-center mt-2" v-if="typeof archivosTemporales[input.id] !== 'undefined' && archivosTemporales[input.id]">
                                                                                <a class="aHover" :href="archivosTemporales[input.id]" target="_blank">Ver archivo cargado</a>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'signature')">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <div :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}">
                                                                            <div v-show="input.valor && input.valor !== ''" class="text-center">
                                                                                <div v-if="typeof signatureData[input.id] !== 'undefined'">
                                                                                    <div>
                                                                                        <img :src="signatureData[input.id]" style="max-height: 60px"/>
                                                                                    </div>
                                                                                    <small style="color: #bdbdbd; font-size: 10px">Firma guardada</small>
                                                                                </div>
                                                                            </div>
                                                                            <div @click="saveSignature(keyInput, input)" class="btn btn-primary float-end btn-sm mt-2">
                                                                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                                                            </div>
                                                                            <div class="text-center">
                                                                                <VueSignaturePad :ref="'signature_'+input.id" style="border: 1px solid #c0c0c0; height: 200px; width: 100%" class="rounded"
                                                                                />
                                                                                <a @click="resetSignature('signature_'+input.id)" class="text-danger cursor-pointer" style="font-size: 15px">Reiniciar</a>
                                                                            </div>

                                                                        </div>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'aprobacion')">
                                                                        <div class="text-center mt-2 mb-2">
                                                                            <div :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}">
                                                                                <fieldset>
                                                                                    <h6>
                                                                                        <span v-if="input.requerido">*</span>{{ input.nombre }}
                                                                                    </h6>
                                                                                    <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                                    <div class="toggle m-auto text-center">
                                                                                        <input type="radio" value="aprobar" :id="input.id + 'aprobarBtn'" :name="input.id + 'aprobacionBtn'" :disabled="input.deshabilitado" v-model="input.valor" @change="campoCumpleCondiciones(input)"
                                                                                               @blur="saveWhenOnBlur(input)"
                                                                                        />
                                                                                        <label :for="input.id + 'aprobarBtn'">Aprobar</label>
                                                                                        <input type="radio" value="rechazar" :id="input.id + 'rechazarBtn'" :name="input.id + 'aprobacionBtn'" :disabled="input.deshabilitado" v-model="input.valor" @change="campoCumpleCondiciones(input)"
                                                                                               @blur="saveWhenOnBlur(input)"
                                                                                        />
                                                                                        <label :for="input.id + 'rechazarBtn'" style="background-color: #f5f5f5">Rechazar</label>
                                                                                    </div>
                                                                                </fieldset>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'process')">
                                                                        <div class="row">
                                                                            <div class="btn btn-primary col-2" @click="consumeServiceForField(input)">
                                                                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                                                                            </div>
                                                                            <div class="col-10 p-0">
                                                                                <multiselect
                                                                                    :options="processCatalog[input.id]"
                                                                                    v-model="input.valor"
                                                                                    :searchable="true"
                                                                                    label="texto"
                                                                                    value-prop="valor"
                                                                                    :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}"
                                                                                    :readonly="input.readonly" :disabled="input.deshabilitado"
                                                                                    @select="async () => {await campoCumpleCondiciones(input); saveWhenOnBlur(input)}"
                                                                                ></multiselect>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'geoposition')">
                                                                        <div class="mb-3">
                                                                            <input type="hidden" v-model="input.valor" :id="input.id">
                                                                            <a class="btn btn-primary" @click="getGeoPosition(input)">
                                                                                <i class="fas fa-location-crosshairs me-2"></i> Presiona aquí para geolocalizar
                                                                            </a>
                                                                        </div>
                                                                        <GoogleMap v-if="typeof input.geoPos !== 'undefined' && input.geoPos.lat !== 0 && input.geoPos.lng !== 0"
                                                                                   :ref="'mapGeo_' + input.id"
                                                                                   :api-key="geopositionKey"
                                                                                   style="width: 100%; height: 400px"
                                                                                   :center="input.geoPos"
                                                                                   :zoom="15"
                                                                        >
                                                                            <Marker :ref="'mapMarkerGeo_' + input.id" :options="{ position: input.geoPos }"/>
                                                                        </GoogleMap>
                                                                        <div v-else>
                                                                            <div class="mapLoadPreview">
                                                                                <i class="fas fa-map mb-4"></i>
                                                                                Mapa de geoposición
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'audio')" class="audioField">
                                                                        <h6>
                                                                            <span v-if="input.requerido">*</span>{{ input.nombre }}
                                                                        </h6>
                                                                        <AudioRecorder :ctoken="cToken" :inputField="input" :campokey="keyInput" :ispublic="isPublic" @save="savedAudioFile"/>
                                                                    </div>
                                                                    <div v-if="input.tipoCampo !== null && input.tipoCampo === 'tags' && (input.mascara === '' || input.mascara === null)">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <div :class="{'': !input.requeridoError, 'requiredInput': input.requeridoError}">
                                                                            <!--<input type="text" :class="{'form-control': !input.requeridoError, 'form-control requiredInput': input.requeridoError}"
                                                                               :readonly="input.readonly"
                                                                               :aria-label="input.nombre"
                                                                               v-model="input.valor"
                                                                               :id="input.id"
                                                                               pattern="\d*"
                                                                               v-maska
                                                                               :data-maska="input.mascara"
                                                                               :data-maska-tokens="input.tokenMask"
                                                                               :disabled="input.deshabilitado"
                                                                               :placeholder="input.ph"
                                                                               :minlength="input.longitudMin"
                                                                               :maxlength="input.longitudMax"
                                                                               @change="campoCumpleCondiciones(input)"
                                                                               @blur="saveWhenOnBlur(input)"
                                                                        >-->
                                                                            <vue3-tags-input :tags="input.valor" @on-tags-changed="handleInputTags($event, input)" :placeholder="input.ph" :add-tag-on-keys="[9, 13, 188]" :allow-duplicates="false"/>
                                                                        </div>
                                                                        <small class="text-muted">Presiona Enter para agregar{{ input.desc }}</small>
                                                                    </div>
                                                                    <div v-if="(input.tipoCampo !== null && input.tipoCampo === 'signature_adv')">
                                                                        <label :for="input.id">{{ input.nombre }}<span v-if="input.requerido" class="requiredAsterisk">*</span></label>
                                                                        <i v-if="input.ttp && input.ttp !== ''" class="fas fa-question-circle ms-2" v-tooltip="input.ttp"></i>
                                                                        <div class="row mb-5">
                                                                            <div class="col-12 col-sm-4" v-for="firmaEA in firmaElectronicaAvanzadaItems">
                                                                                <div class="fileGalleryItemGen">
                                                                                    <div class="text-center rounded cursor-pointer">
                                                                                        <div class="mb-2">{{ firmaEA.n }}</div>
                                                                                        <div class="buttonsFile">
                                                                                            <a class="btn btn-primary mt-1 btn-sm" target="_blank" @click="openWindow(firmaEA.lf)">
                                                                                                <div><i class="fas fa-eye"></i></div>
                                                                                                <div>Ver documento</div>
                                                                                            </a>
                                                                                            <a class="btn btn-primary mt-1 btn-sm" target="_blank"  @click="openWindow(firmaEA.l);">
                                                                                                <div><i class="fas fa-signature"></i></div>
                                                                                                <div>Firmar</div>
                                                                                            </a>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <!--Repetición-->
                                                                    <div v-if="input.repetibleSb" class="text-end repetibleContainerBtn">
                                                                        <span class="cursor-pointer text-danger me-3" @click="subFieldsGroup(input, keyInput, currentTabIndex)"><i class="fas fa-trash cursor-pointer"></i> Eliminar</span>
                                                                    </div>
                                                                </div>
                                                                <!--Repetición-->
                                                                <div v-if="input.repetibleSb" class="col-sm-12 col-12 mb-4">
                                                                    <span class="cursor-pointer text-primary" @click="addFieldsGroup(input, keyInput, currentTabIndex)"><i class="fas fa-plus-circle cursor-pointer"></i> Agregar</span>
                                                                    <hr>
                                                                </div>
                                                            </template>
                                                        </template>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="outputView" v-if="(typeof flujoActivo.salidaReplaced !== 'undefined')">
                                            <div v-html="flujoActivo.salidaReplaced"></div>
                                        </div>
                                        <div class="mb-3" v-if="(typeof flujoActivo.ocrOptions !== 'undefined')">
                                            <div class="row">
                                                <div :class="{'col-12': !previewOcrEnabled, 'col-12 col-sm-6': previewOcrEnabled}">
                                                    <div v-if="Object.keys(flujoActivo.ocrOptions).length > 0">
                                                        <h5 class="mb-3 fw-bold">Tokens:</h5>
                                                        <div v-for="input in flujoActivo.ocrOptions">
                                                            <div class="mt-3">
                                                                <label :for="input.id">{{ input.nombre }}</label>
                                                                <input type="text" :class="{'form-control': !input.requeridoError, 'form-control requiredInput': input.requeridoError}"
                                                                       :readonly="input.readonly || input.valorCalculado"
                                                                       :label="input.nombre"
                                                                       :aria-label="input.nombre"
                                                                       v-model="input.valor"
                                                                       :id="input.id"
                                                                       v-maska
                                                                       :data-maska="input.mascara"
                                                                       :data-maska-tokens="input.tokenMask"
                                                                       :disabled="input.deshabilitado"
                                                                       :placeholder="input.ph"
                                                                       :minlength="input.longitudMin"
                                                                       :maxlength="input.longitudMax"
                                                                       @change="campoCumpleCondiciones(input)"
                                                                       @blur="saveWhenOnBlur(input)">
                                                                <small class="text-muted">{{ input.desc }}</small>
                                                            </div>
                                                            <div class="mt-3">
                                                                <h6 class="fw-bold">
                                                                    Opciones:
                                                                </h6>
                                                                <div v-for="option in input.options">
                                                                    <div :class="{'form-check': !input.requeridoError, 'form-check requiredInput': input.requeridoError}">
                                                                        <input class="form-check-input" :name="input.id" :checked="false" :value="option" type="radio" @change="input.valor = option"
                                                                               @blur="saveWhenOnBlur(input)">
                                                                        <label class="form-check-label"> {{ option }}</label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div v-if="(typeof flujoActivo.ocrData !== 'undefined')">
                                                        <h5 class="mb-3 fw-bold">Tablas:</h5>
                                                        <div class="mb-3" v-for="(table, nameTable) in flujoActivo.ocrData">
                                                            <h6 class="mb-3 text-capitalize fw-bold">{{ nameTable }}:</h6>
                                                            <div>
                                                                <div v-for="(filaTmp, keyTr) in table">
                                                                    <h6 class="mb-2 text-muted fw-body">Fila {{ parseInt(keyTr) + 1 }}</h6>
                                                                    <div class="mb-3">
                                                                        <div class="row">
                                                                            <div class="col-12 col-sm-6 mb-2" v-for="(item, keyItem) in filaTmp">
                                                                                <span class="text-capitalize">{{ item.header }}</span>:
                                                                                <div class="row" v-if="item.tipoCampo === 'ocrTableService'">
                                                                                    <div class="col-12 col-sm-3">
                                                                                        <div class="btn btn-primary w-100" @click="calcularCatalogosOcrTablas(item, filaTmp, nameTable, keyTr, keyItem)">
                                                                                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="col-12 col-sm-9">
                                                                                        <div class="p-0">
                                                                                            <select
                                                                                                v-if="item.fillBy === 'con'"
                                                                                                class="form-select"
                                                                                                v-model="item.valor"
                                                                                                aria-label=".form-select-lg example"
                                                                                                @blur="saveOptionsOcrTable(item, nameTable)"
                                                                                            >
                                                                                                <option v-for="(inp , ind) in item.options" :value="inp[item.conValue]" :key="ind">
                                                                                                    {{ inp[item.conLabel] }}
                                                                                                </option>
                                                                                            </select>
                                                                                            <multiselect v-else
                                                                                                         :options="obtenerItemsPorCatalogoMSOcr(item.options, item.catalogoLabel, item.catalogoValue)"
                                                                                                         v-model="item.valor"
                                                                                                         :searchable="true"
                                                                                                         @select="saveWhenOnBlur(item)"></multiselect>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                                <input v-else class="form-control" type="text" v-model="item.valor"
                                                                                       @blur="saveWhenOnBlur(item)"
                                                                                >
                                                                            </div>
                                                                            <!--
                                                                            <div class="col-12 col-sm-6 mb-2">
                                                                                <span class="text-capitalize">Validar en sistema</span>:
                                                                                <div class="row">
                                                                                    <div class="col-12 col-sm-3">
                                                                                        <div class="btn btn-primary w-100" @click="">
                                                                                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="col-12 col-sm-9">
                                                                                        <div class="p-0">
                                                                                            <multiselect
                                                                                                :options="[]"
                                                                                                :searchable="true"
                                                                                                label="texto"
                                                                                                value-prop="valor"
                                                                                                @select="async () => {}"
                                                                                            ></multiselect>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            -->
                                                                        </div>
                                                                    </div>
                                                                    <hr>
                                                                </div>
                                                            </div>
                                                            <!--<table class="table table-bordered col-12 p-0">
                                                                <thead>
                                                                    <tr>
                                                                        <th class="fw-bold text-center" v-for="(th, keyth) in table.header">{{th}}</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <tr v-for="(tr, keytr) in table.data">
                                                                        <td v-if="tr !== null" v-for="(th, keyth) in table.header">
                                                                            <div v-if="(tr[th].tipoCampo !== null && tr[th].tipoCampo === 'process')">
                                                                                <div class="row">
                                                                                    <div class="btn btn-primary col-2" @click="consumeServiceForField(tr[th], {nameTable, keytr})"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                                                                                    <div class="col-9 p-0">
                                                                                        <multiselect
                                                                                            :options="processCatalog[tr[th]['id']]"
                                                                                            v-model="tr[th].valor"
                                                                                            :searchable="true"
                                                                                            label="texto"
                                                                                            value-prop="valor"
                                                                                            :class="{'': !tr[th].requeridoError, 'requiredInput': tr[th].requeridoError}"
                                                                                            :readonly="tr[th].readonly" :disabled="tr[th].deshabilitado"
                                                                                            @select="async () => {await campoCumpleCondiciones(tr[th]); saveWhenOnBlur(tr[th])}"
                                                                                        ></multiselect>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            <div v-else-if="(tr[th].tipoCampo !== null && (tr[th].tipoCampo === 'text' || tr[th].tipoCampo === 'default'))">
                                                                                <input type="text" :class="{'form-control': !tr[th].requeridoError, 'form-control requiredInput': tr[th].requeridoError}"
                                                                                    :readonly="tr[th].readonly || tr[th].valorCalculado"
                                                                                    :label="tr[th].nombre"
                                                                                    :aria-label="tr[th].nombre"
                                                                                    v-model="tr[th].valor"
                                                                                    :id="tr[th].id"
                                                                                    v-maska
                                                                                    :data-maska="tr[th].mascara"
                                                                                    :data-maska-tokens="tr[th].tokenMask"
                                                                                    :disabled="tr[th].deshabilitado"
                                                                                    :placeholder="tr[th].ph"
                                                                                    :minlength="tr[th].longitudMin"
                                                                                    :maxlength="tr[th].longitudMax? tr[th].longitudMax : 20"
                                                                                    @change="campoCumpleCondiciones(tr[th])"
                                                                                    @blur="saveWhenOnBlur(tr[th])">
                                                                            </div>
                                                                            <div v-else>
                                                                                <div class="text-muted">
                                                                                    No disponible
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                </tbody>
                                                            </table>-->
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div :class="{ocrPreview: !previewOcrEnabled, ocrPreviewEnabled: previewOcrEnabled}">
                                                <i class="cerrarBtn fas fa-times-circle" @click="previewOcrEnabled = false"></i>
                                                <object :data="flujoActivo.ocrFile.data"/>
                                            </div>
                                        </div>
                                        <COffcanvas placement="end" :visible="showComentariosBar" @hide="() => { showComentariosBar = !showComentariosBar }">
                                            <COffcanvasHeader>
                                                Comentarios
                                                <CCloseButton class="text-reset" @click="() => { showComentariosBar = false }"/>
                                            </COffcanvasHeader>
                                            <COffcanvasBody>
                                                <div class="chatBar">
                                                    <div class="chatBarContainer">
                                                        <div class="chatBarItem" v-for="item in comentarios">
                                                            <div class="chatBarItemUser">{{ item.usuario }},
                                                                <span :class="{'text-success': item.a === 'privado', 'text-danger': item.a === 'publico'}">{{ item.a }}</span>
                                                            </div>
                                                            {{ item.comentario }}
                                                        </div>
                                                    </div>
                                                    <div class="chatBarInput pt-2">
                                                        <div class="input-group">
                                                            <input type="text" class="form-control" placeholder="Escribe aquí" v-model="comentarioTmp" v-on:keyup.enter="sendComentario" style="width: 70%;">
                                                            <select class="form-control" v-model="comentarioAcceso" v-if="!isPublic">
                                                                <option value="privado">Privado</option>
                                                                <option value="publico">Público</option>
                                                            </select>
                                                            <button class="btn btn-primary" @click="sendComentario">
                                                                <i class="fa fa-paper-plane me-1"></i></button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </COffcanvasBody>
                                        </COffcanvas>
                                    </div>
                                    <div class="col-6" v-if="flujoActivo.typeObject === 'ocr' && showPreviewOcr">
                                        <div class="col-12">
                                            <button @click="changeScalePreviewOcr('sub')" class="btn btn-primary me-2">
                                                <i class="fa-solid fa-magnifying-glass-minus"></i>
                                            </button>
                                            <button @click="changeScalePreviewOcr('add')" class="btn btn-primary me-2">
                                                <i class="fa-solid fa-magnifying-glass-plus"></i>
                                            </button>
                                        </div>
                                        <div class="col-12" style="overflow-x: scroll; overflow-y: scroll">
                                            <img v-if="!!flujoActivo.ocrFile.type && flujoActivo.ocrFile.type === 'image'" :src="flujoActivo.ocrFile.data" :style="`width: ${scaleOcr}%`"/>
                                            <vue-pdf-embed
                                                v-else
                                                :source="flujoActivo.ocrFile.data"
                                                :style="{transform: `scale(${scaleOcr/100})`, transformOrigin: 'top left'}"
                                            />
                                        </div>
                                    </div>
                                    <div v-if="flujoActivo.typeObject === 'ocr'">
                                        <button @click="showPreviewOcr = !showPreviewOcr" class="btn btn-primary float-end mt-3">
                                            {{ !showPreviewOcr ? 'Mostrar Archivo' : 'Ocultar Archivo' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="text-end wizardButtons">
                            <!--<div v-if="metaMapActive" class="text-center my-3">
                                <button class="btn btn-primary" @click="showMetaMap"><i class="fas fa-user me-2"></i>Iniciar prueba de vida</button>
                            </div>-->
                            <div>
                                <CButton v-if="flujoHasPrev && flujoActivo.btnText.prev !== '' && ((isPublic && !flujoActivo.btnS.p) || !isPublic)" color="primary" @click="continuarCotizacion('prev')" class="custom-formButton">
                                    <i class="fas fa-arrow-circle-left me-2"></i> {{ flujoActivo.btnText.prev }}
                                </CButton>
                                <CButton v-if="flujoHasNext && flujoActivo.btnText.next !== '' && ((isPublic && !flujoActivo.btnS.n) || !isPublic)" color="primary" @click="continuarCotizacion('next')" class="custom-formButton">
                                    <i class="fas fa-arrow-circle-right me-2"></i> {{ flujoActivo.btnText.next }}
                                </CButton>
                                <CButton v-if="!flujoHasNext && flujoActivo.btnText.finish !== '' && ((isPublic && !flujoActivo.btnS.f) || !isPublic)" color="primary" @click="continuarCotizacion('next', (isPublic ? 'guardada' : 'finalizada'))" class="custom-formButton">
                                    <i class="fas fa-check-circle me-2"></i> {{ flujoActivo.btnText.finish }}
                                </CButton>
                                <CButton v-if="flujoActivo.btnText.cancel !== '' && ((isPublic && !flujoActivo.btnS.c) || !isPublic)" color="danger" @click="cancelarCotizacion" class="custom-formButton">
                                    <i class="fas fa-trash me-2"></i> {{ flujoActivo.btnText.cancel }}
                                </CButton>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 wizardBox" v-if="!isPublic">
                        <div>
                            <h5>Archivos adjuntos</h5>
                        </div>
                        <div>
                            <div class="globalModal" v-if="showImagePreview">
                                <div class="globalModalContainer text-center p-5">
                                    <div @click="showImagePreview = false" class="globalModalClose mt-3">
                                        <i class="fas fa-times-circle"></i></div>
                                    <img :src="imagePreviewTmp.url" style="max-width: 100%"/>
                                    <div class="text-center">
                                        <a class="btn btn-primary mt-5" :href="imagePreviewTmp.url" :download="imagePreviewTmp.name" target="_blank"><i class="fa fa-download me-2"></i>Descargar</a>
                                        <a class="btn btn-danger mt-5 ms-3" :href="imagePreviewTmp.url" :download="imagePreviewTmp.name" target="_blank"><i class="fa fa-trash me-2"></i>Eliminar</a>
                                    </div>
                                </div>
                            </div>
                            <div class="row" id="fileAttachGallery">


                                <template v-for="(file, key) in previewFiles">
                                    <div class="col-12 col-sm-2" v-if="!file.salida">
                                        <div class="fileGalleryItem">
                                            <div class="fileGalleryItemText">
                                                {{ file.name }}
                                            </div>
                                            <a
                                                :key="'attachTmp_' + key"
                                                :href="file.url"
                                                target="_blank"
                                                rel="noreferrer"
                                                :data-pswp-width="900"
                                                :data-pswp-height="900"
                                                :data-download="file.download"
                                            >
                                                <img :src="file.url" :class="'previewFile-' + file.type"/>
                                            </a>
                                        </div>
                                    </div>
                                </template>

    <!--                        <template v-for="file in previewFiles">
                                <div class="col-12 col-sm-3" v-if="!file.salida">



                                    <div class="mb-3 fw-bold">{{file.name}}</div>
                                    <div class="text-center rounded cursor-pointer" style="padding: 20px; background: #d7d7d7; height: 100px" @click="openPreview(file)">
                                        <div v-if="file.type === 'image'">
                                            <img :src="file.url" style="max-height: 60px; max-width: 100%">
                                        </div>
                                        <div v-if="file.type === 'pdf'">
                                            <div style="min-height: 60px">
                                                <a class="btn btn-primary mt-3" target="_blank">Ver PDF</a>
                                            </div>
                                        </div>
                                        <div v-if="file.type === 'docx'">
                                            <div style="min-height: 60px">
                                                <a class="btn btn-primary mt-3" target="_blank">Ver documento</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>-->
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 wizardBox" v-if="!isPublic">
                        <div>
                            <h5>Archivos generados</h5>
                        </div>
                        <div>
                            <div class="row">
                                <template v-for="file in previewFiles">
                                    <div class="col-12 col-sm-4" v-if="file.salida">
                                        <div class="fileGalleryItemGen">
                                            <div class="text-center rounded cursor-pointer">
                                                <div class="mb-2">{{ file.label }}</div>
                                                <div v-if="file.type === 'pdf'" class="buttonsFile">
                                                    <a class="btn btn-primary mt-1 btn-sm" target="_blank" @click="openPreview(file)">
                                                        <div><i class="fas fa-eye"></i></div>
                                                        <div>Ver</div>
                                                    </a>
                                                    <a v-if="file.sign" class="btn btn-primary mt-1 btn-sm" target="_blank" @click="firmaElectronicaAvanzada(file)">
                                                        <div><i class="fas fa-signature"></i></div>
                                                        <div v-if="file.signE === 'crear'">Firma</div>
                                                        <div v-if="file.signE === 'creada'">Validar</div>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 wizardBox" v-if="!isPublic">
                        <div>
                            <h5>Resumen de formulario</h5>
                        </div>
                        <div>
                            <div class="text-muted mb-3">
                                * Únicamente se muestran secciones y campos llenos
                            </div>
                            <div v-for="item in resumen" class="mb-4">
                                <div v-if="typeof item.campos !== 'undefined'">
                                    <h6 class="cursor-pointer fw-bold" @click="item.active = !item.active">{{ item.nombre || 'Sin nombre' }}</h6>
                                    <hr>
                                    <div class="row" v-if="!item.active">
                                        <template v-if="typeof item.campos !== 'undefined'">
                                            <template v-for="(campo, key) in item.campos">
                                                <div class="col-12 col-sm-4 mb-4" v-if="campo.value !== '' && (campo.t !== 'signature' && campo.t !== 'file')">
                                                    <div class="text-secondary">
                                                        {{ campo.label }}
                                                    </div>
                                                    <div v-html="campo.value"></div>
                                                </div>
                                            </template>
                                        </template>
                                        <template v-else>
                                            <div class="col-12 text-danger">
                                                Sin campos llenos
                                            </div>
                                        </template>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="!isPublic && typeof authInfo.m !== 'undefined' && typeof authInfo.m['tareas/admin/usuario-asignado'] !== 'undefined' && authInfo.m['tareas/admin/usuario-asignado']" class="mt-3 wizardBox">
                        <div class="mt-2">
                            <div>
                                <h5>Edición de usuario</h5>
                            </div>
                            <div class="text-center">
                                <div class="text-start">
                                    <h6>Cambiar usuario asignado</h6>
                                    <div>
                                        <multiselect :options="users" v-model="usuarioEditar" :searchable="true"></multiselect>
                                    </div>
                                    <div class="text-end mt-3">
                                        <button class="btn btn-primary" @click="editarUsuarioCotizacion">Cambiar usuario</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="!isPublic && typeof authInfo.m !== 'undefined' && typeof authInfo.m['tareas/admin/usuario-asignado'] !== 'undefined' && authInfo.m['tareas/admin/usuario-asignado']" class="mt-3 wizardBox">
                        <div class="mt-2">
                            <div>
                                <h5>Cambio de estado</h5>
                            </div>
                            <div class="text-center">
                                <div class="text-start">
                                    <h6>Cambiar estado de formulario</h6>
                                    <div>
                                        <multiselect :options="estados" v-model="estadoEditar" :searchable="true"></multiselect>
                                    </div>
                                    <div class="text-end mt-3">
                                        <button class="btn btn-primary" @click="editarEstadoCotizacion">Cambiar estado</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="!isPublic">
                        <div class="mt-3 wizardBox">
                            <div>
                                <strong>Bitácora</strong>
                                <div v-if="producto.modoPruebas" class="mt-3">
                                    <h5 class="text-danger"><i class="fas fa-warning me-2"></i> Modo pruebas activo</h5>
                                </div>
                            </div>
                            <div v-if="!productoid" style="overflow: auto; max-height: 700px">
                                <div class="row mb-2" v-for="bit in bitacora">
                                    <div class="col-12"><b>Operación:</b> {{ bit.log }}</div>
                                    <div class="col-12 col-sm-3"><b>Fecha:</b> {{ bit.createdAt }}</div>
                                    <div class="col-12 col-sm-3"><b>Usuario:</b> {{ bit.usuarioNombre }}</div>
                                    <div class="col-12 col-sm-3"><b>Corporativo:</b> {{ bit.usuarioCorporativo }}</div>
                                    <div class="col-12" v-if="bit.dataInfo && bit.dataInfo !== ''">
                                        <div class="mb-3 p-3 bg-light" v-html="bit.dataInfo"></div>
                                    </div>
                                    <div class="col-12">
                                        <hr>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
    <!--<div class="poweredBy">
        Powered by <a href="https://about.cworkflow.com" target="_blank">Cloud Workflow</a>
    </div>-->
</template>
<script>
import toolbox from "@/toolbox";
import 'form-wizard-vue3/dist/form-wizard-vue3.css'
import '@vueform/slider/themes/default.css';
import login from "@/views/pages/Login.vue";
import {CChart} from "@coreui/vue-chartjs";
import {vMaska} from "maska";
import {useRoute} from 'vue-router';
import Multiselect from '@vueform/multiselect'

// Import FilePond
import vueFilePond from 'vue-filepond';
import 'filepond/dist/filepond.min.css';
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css';
import FilePondPluginImagePreview from "filepond-plugin-image-preview";

import Button from "@/views/forms/form_elements/FormElementButton.vue";
import {mapGetters} from "vuex";
import * as dayjs from 'dayjs'
import Slider from '@vueform/slider'
import Select from "@/views/forms/Select.vue";
// photogallery
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';
import {config} from "/src/config";
import VuePdfEmbed from 'vue-pdf-embed';
import InputGroup from "@/views/forms/InputGroup.vue";
import {GoogleMap, Marker} from "vue3-google-map";
import AudioRecorder from "@/components/AudioRecorder.vue";
// carousel
import 'vue3-carousel/dist/carousel.css';
import {Carousel, Navigation, Slide} from 'vue3-carousel';
import Vue3TagsInput from 'vue3-tags-input';


const FilePond = vueFilePond(FilePondPluginImagePreview);

export default {
    name: 'Tables',
    props: ['tokenProducto', 'tokenCotizacion', 'isPublic', 'tokenLinking'],
    directives: {maska: vMaska},
    components: {
        InputGroup,
        Select,
        Button,
        login,
        CChart,
        FilePond,
        useRoute,
        Multiselect,
        Slider,
        VuePdfEmbed,
        GoogleMap,
        Marker,
        AudioRecorder,
        Carousel,
        Slide,
        Navigation,
        Vue3TagsInput,
    },
    data() {
        return {
            pToken: "",
            cToken: "",
            lToken: "",
            producto: {},
            cotizacion: {},
            logHistory: [],
            flujoActivo: {},
            flujoHasPrev: false,
            flujoHasNext: false,
            currentTabIndex: 0,
            estadoCotizacion: '',
            showCotizacion: false,
            showCotizacionDesc: '',
            bitacora: {},
            resumen: {},
            users: [],
            usuarioEditar: 0,

            // pasos
            configSteps: {
                itemsToShow: 1,
                wrapAround: false,
                transition: 100,
                autoplay: 0,
                mouseDrag: 0,
                touchDrag: 0,
            },

            // estados
            catalogosCheckDepends: {},
            catalogos: {},

            // estados
            estados: {},
            estadoEditar: '',

            // condicionales
            seccionesCumplidoras: {},
            camposCumplidores: {},
            camposValores: {},

            // calculados
            camposCalculados: {},
            userVars: [],

            // preview de adjuntos
            previewFiles: {},
            showImagePreview: false,
            imagePreviewTmp: {},
            imageOcrTmp: {},

            // progresión
            showProgresion: false,
            progresion: {},

            // toda la data historica
            allFieldsData: {},
            signatureData: {},

            // Comentarios
            showComentariosBar: false,
            comentarios: {},
            comentarioTmp: '',
            comentarioAcceso: 'privado',


            // galería
            lightboxAttachment: false,

            // ocr
            showPreviewOcr: false,
            scaleOcr: 100,
            previewOcrEnabled: false,

            //proceso
            processCatalog: {},

            // geolocalización
            geopositionKey: '',
            geopositionString: '',
            geoposition: {
                lat: 0,
                lng: 0
            },

            // parseo variables hash
            hashVarsString: '',
            hashVars: {},

            // Prueba de vida
            metaMap: false,
            metaMapActive: false,
            metaMapData: {},

            // archivos preview tmp
            archivosTemporales:  {},

            // ocr
            verifyModal: false,
            ocrFieldsExists: false,

            // firma electrónica avanzada
            firmaElectronicaAvanzadaItems: {},
        };
    },
    mounted() {
        const self = this;
        if (self.tokenCotizacion === '') {
            this.cToken = 'view';
        }

        // parseo de variables url
        this.hashVars = this.hashParser();

        // validación de acceso custom
        if (!this.isPublic && typeof this.authInfo.m['tareas/admin/usuario-asignado'] !== 'undefined' && this.authInfo.m['tareas/admin/usuario-asignado']) {
            this.getUsers();
        }

        this.loadData(function () {
            self.getFlujo();
        });

        // mapas
        this.geopositionKey = config.googleMapsApiKey;

        // this.enableMetaMap();
    },
    unmounted() {
        if (this.lightboxAttachment) {
            this.lightboxAttachment.destroy();
            this.lightboxAttachment = null;
        }
    },
    computed: {
        ...mapGetters({
            authLogged: 'authLogged',
            authInfo: 'authInfo',
        }),

    },
    watch: {
        tokenProducto: function (val) {
            const self = this;
            this.loadData(function () {
                self.getFlujo();
            });
        },
    },
    methods: {
        hashParser() {
            const urlTmp = window.location.href;
            const paramsUrl = (urlTmp !== '') ? urlTmp.replace('#', '') : '';
            const n = paramsUrl.indexOf('?');
            const UrlClean = paramsUrl.substring((n != -1 ? n + 1 : 0) , paramsUrl.length);

            let hasParams = false;
            let hashParams = {};
            if (UrlClean && UrlClean !== '' && n !== -1) {
                let e,
                    a = /\+/g,  // Regex for replacing addition symbol with a space
                    r = /([^&;=]+)=?([^&;]*)/g,
                    d = function (s) {
                        return decodeURIComponent(s.replace(a, " "));
                    },
                    q = UrlClean;

                while (e = r.exec(q)) {
                    hashParams[d(e[1])] = d(e[2]);
                    hasParams = true;
                }
            }

            if (hasParams) {
                this.hashVarsString = UrlClean;
            }
            //console.log(this.hashVarsString)

            return hashParams;
        },
        loadData(callback) {
            const self = this;
            this.pToken = this.tokenProducto;
            this.cToken = this.tokenCotizacion;
            this.lToken = this.tokenLinking;

            if (this.pToken !== '') {
                toolbox.doAjax('POST', 'productos/by/token/' + this.pToken, {
                    rc: true,
                }, function (response) {
                    if (response.status) {
                        self.producto = (typeof response.data[0] !== 'undefined' ? response.data[0] : {});
                        self.setColors();
                        if (typeof callback === 'function') {
                            callback();
                        }
                    }
                    else {
                        toolbox.alert('Ha ocurrido un error obteniendo el producto', 'danger');
                    }
                }, function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
            }
        },

        // Control de cotización
        isCoti() {
            return (this.cToken && this.cToken !== '' && this.cToken !== 'view');
        },
        iniciarCotizacion() {
            const self = this;
            toolbox.confirm('Se iniciará un formulario nuevo, ¿desea continuar?', function () {
                toolbox.doAjax('POST', 'tareas/iniciar-cotizacion' + (self.isPublic ? '/public' : ''), {
                    token: self.pToken,
                }, function (response) {
                    if (response.status) {
                        self.cToken = response.data.token;

                        if (!!self.lToken) {
                            toolbox.doAjax('POST', 'tareas/linking-cotizaciones' + (self.isPublic ? '/public' : ''), {
                                token: self.cToken,
                                lToken: self.lToken
                            }, function (response) {

                                if (self.hashVarsString !== '') {
                                    if (self.isPublic) {
                                        self.$router.push('/f/' + self.pToken + '/' + self.cToken + '?' + self.hashVarsString);
                                    }
                                    else {
                                        self.$router.push('/flow/' + self.pToken + '/' + self.cToken + '?' + self.hashVarsString);
                                    }
                                }
                                else {
                                    if (self.isPublic) {
                                        self.$router.push('/f/' + self.pToken + '/' + self.cToken);
                                    }
                                    else {
                                        self.$router.push('/flow/' + self.pToken + '/' + self.cToken);
                                    }
                                }

                                setTimeout(function () {
                                    location.reload();
                                }, 800);

                            }, function (response) {
                                toolbox.alert(response.msg, 'danger');
                            })
                        }
                        else {
                            if (self.hashVarsString !== '') {
                                if (self.isPublic) {
                                    self.$router.push('/f/' + self.pToken + '/' + self.cToken + '?' + self.hashVarsString);
                                }
                                else {
                                    self.$router.push('/flow/' + self.pToken + '/' + self.cToken + '?' + self.hashVarsString);
                                }
                            }
                            else {
                                if (self.isPublic) {
                                    self.$router.push('/f/' + self.pToken + '/' + self.cToken);
                                }
                                else {
                                    self.$router.push('/flow/' + self.pToken + '/' + self.cToken);
                                }
                            }
                            setTimeout(function () {
                                location.reload();
                            }, 800);
                        }
                    }
                    else {
                        toolbox.alert('Ha ocurrido un error obteniendo el producto', 'danger');
                    }
                }, function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
            })
        },
        getFlujo() {
            const self = this;
            if (self.cToken !== 'view') {
                toolbox.doAjax('POST', 'tareas/calcular-paso' + (self.isPublic ? '/public' : ''), {
                    token: self.cToken,
                }, function (response) {
                    self.flujoActivo = response.data.actual;
                    self.flujoHasNext = (typeof response.data.next !== 'undefined' && response.data.next);
                    self.flujoHasPrev = (typeof response.data.prev !== 'undefined' && response.data.prev);
                    self.estadoCotizacion = response.data.estado;

                    // validación de campos ocr
                    self.flujoActivo.formulario.secciones.forEach(function (seccion, seccionKey) {
                        self.flujoActivo.formulario.secciones[seccionKey].campos.forEach(function (a, b) {
                            if (a.tipoCampo === 'file' && a.ocr && a.ocrTPl) {
                                self.ocrFieldsExists = true;
                            }
                        })
                    });



                    if (typeof response.data.estado !== 'undefined' && !self.isPublic) {
                        self.getResumen();
                    }
                    if (typeof response.data.bit !== 'undefined' && !self.isPublic) {
                        self.bitacora = response.data.bit;
                    }
                    /*if (typeof response.data.visible !== 'undefined') {
                        self.noVisible = (response.data.visible !== '' ? response.data.visible : false);
                    }*/
                    if (typeof response.data.c !== 'undefined') {
                        self.cotizacion = (response.data.c !== '' ? response.data.c : {});
                    }
                    if (typeof response.data.e !== 'undefined') {
                        self.estados = (response.data.e !== '' ? response.data.e : {});
                    }
                    if (typeof response.data.d !== 'undefined') {
                        self.allFieldsData = (response.data.d !== '' ? response.data.d : {});
                        //self.setSavedValuesForm(self.isPublic);
                        self.setSavedValuesForm(true);
                        /*self.allFieldsData.forEach(function (campo) {
                            self.camposValores[campo.id] = ((campo.valor) ? campo.valor.toString() : '');
                        })*/
                    }

                    // se calcula si se muestra la cotización
                    if (self.estadoCotizacion === 'creada') {
                        self.showCotizacion = true;
                    }
                    else {
                        if (self.estadoCotizacion === 'expirada') {
                            self.showCotizacion = false;
                            self.showCotizacionDesc = 'El formulario ha expirado';
                        }
                        else {
                            self.showCotizacion = true;
                        }
                    }

                    // visibilidad
                    if (!self.flujoActivo) {
                        self.showCotizacion = false;
                        self.showCotizacionDesc = self.cotizacion.ed;
                    }

                    self.previewAdjunto();
                    self.campoCumpleCondiciones();
                    self.filtrarCatalogos();
                    self.firmaElectronicaAvanzadaGetForUser();

                    // regreso el tab index para mostrar desde inicio la siguiente pantalla
                    self.currentTabIndex = 0;
                }, function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
            }
        },
        setColors() {
            // si estoy buscando
            if (typeof this.producto.extraData !== 'undefined' && typeof this.producto.extraData.c !== 'undefined' && typeof this.producto.extraData.c.primary !== 'undefined' && this.producto.extraData.c.primary) {
                let root = document.documentElement;
                //console.log(this.producto.extraData.c.primary);
                // root.style.setProperty('--cui-primary-form', typeof this.producto.extraData.c.primary.hex8 !== 'undefined' ? this.producto.extraData.c.primary.hex8 : '#0f6cbd');
                root.style.setProperty('--primary-color-form', typeof this.producto.extraData.c.primary.hex8 !== 'undefined' ? this.producto.extraData.c.primary.hex8 : '#0f6cbd');
                root.style.setProperty('--secondary-color-form', typeof this.producto.extraData.c.primary.hex8 !== 'undefined' ? this.producto.extraData.c.primary.hex8 : '#0f6cbd');
                root.style.setProperty('--third-color-form', typeof this.producto.extraData.c.primary.hex8 !== 'undefined' ? this.producto.extraData.c.primary.hex8 : '#0f6cbd');
            }

        },
        setSavedValuesForm(replaceValues) {
            const self = this;
            if (!replaceValues) {
                replaceValues = false;
            }
            // colocar datos en campos guardados
            self.camposValores = {};
            Object.keys(self.allFieldsData).map(function (a) {
                self.camposValores[self.allFieldsData[a].id] = self.allFieldsData[a].valor;
            })

            if (replaceValues) {
                self.flujoActivo.formulario.secciones.forEach(function (seccion, seccionKey) {
                    self.flujoActivo.formulario.secciones[seccionKey].campos.forEach(function (a, b) {
                        if (typeof self.camposValores[a.id] !== 'undefined' && self.camposValores[a.id] && self.camposValores[a.id] !== '') {
                            a.valor = self.camposValores[a.id];
                            //console.log(a.valor);
                        }
                        if (a.tipoCampo === 'multiselect' || a.tipoCampo === 'checkbox') {
                            if (!a.valor) {
                                a.valor = []
                            }
                            else {
                                if (Array.isArray(self.camposValores[a.id])) {
                                    a.valor = self.camposValores[a.id];
                                }
                                else {
                                    a.valor = Object.keys(JSON.parse(self.camposValores[a.id]));
                                }
                            }
                        }
                    })
                });
            }
        },
        getResumen() {
            const self = this;
            toolbox.doAjax('POST', 'tareas/get-resumen', {
                token: self.cToken,
            }, function (response) {
                self.resumen = response.data;
            }, function (response) {
                toolbox.alert(response.msg, 'danger');
            })
        },
        cancelarCotizacion(text) {
            const self = this;
            toolbox.confirm('Se cancelará el formulario, esta acción no se puede revertir, ¿desea continuar?', function () {

                toolbox.doAjax('POST', 'tareas/cambiar-estado' + (self.isPublic ? '/public' : ''), {
                    token: self.cToken,
                    estado: 'cancelada',
                }, function (response) {
                    toolbox.alert(response.msg);
                    self.loadData();
                }, function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
            })
        },
        editarUsuarioCotizacion() {
            const self = this;
            toolbox.confirm('Se cambiará el usuario asignado a este formulario, ¿desea continuar?', function () {

                toolbox.doAjax('POST', 'tareas/cambiar-usuario', {
                    token: self.cToken,
                    usuarioId: self.usuarioEditar,
                }, function (response) {
                    toolbox.alert(response.msg);
                    self.getFlujo();
                }, function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
            })
        },
        editarEstadoCotizacion() {
            const self = this;
            toolbox.confirm('Se cambiará el estado asignado a este formulario, ¿desea continuar?', function () {

                toolbox.doAjax('POST', 'tareas/editar-estado', {
                    token: self.cToken,
                    estado: self.estadoEditar,
                }, function (response) {
                    toolbox.alert(response.msg);
                    self.getFlujo();
                }, function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
            })
        },
        getUsers() {

            const self = this;
            toolbox.doAjax('GET', 'users/list', {},
                function (response) {
                    //self.items = response.data;
                    self.users = [];
                    Object.keys(response.data).map(function (a, b) {
                        self.users.push({
                            value: response.data[a].id,
                            label: response.data[a].name + "(" + response.data[a].email + ")",
                        })
                    })
                },
                function (response) {
                    //toolbox.alert(response.msg, 'danger');
                })
        },
        removeRequiredClass() {
            const self = this;
            if (typeof this.flujoActivo.formulario.secciones !== 'undefined') {
                self.flujoActivo.formulario.secciones.forEach(function (seccion, keySeccion) {
                    self.flujoActivo.formulario.secciones[keySeccion].campos.forEach(function (a) {
                        a.requeridoError = false;
                    });
                })
            }
        },
        evaluarRequeridos() {

            const self = this;

            let requeridosFail = false;
            let requeridosFailInSection = false;
            let arrCampos = {};
            if (typeof this.flujoActivo.formulario.secciones !== 'undefined') {

                self.flujoActivo.formulario.secciones.forEach(function (seccion, keySeccion) {
                    self.flujoActivo.formulario.secciones[keySeccion].campos.forEach(function (a) {

                        if (typeof arrCampos[a.id] === 'undefined') {
                            arrCampos[a.id] = {};
                        }
                        if (a.fillBy !== 'con') {
                            // data del catálogo
                            if (a.catalogoId && a.catalogoId !== '') {
                                const dataTmp = self.obtenerItemsPorCatalogoMS(a.id, a.catalogoLabel, a.catalogoValue);
                                Object.keys(dataTmp).map(function (k) {
                                    if (typeof dataTmp[k] !== 'undefined' && dataTmp[k].value === a.valor) {
                                        arrCampos[a.id]['vs'] = dataTmp[k].label;
                                    }
                                })
                            }
                        }
                        else {
                            if (!!a.fillByCon) {
                                const catalogoCon = self.catalogos['conn_' + a.fillByCon];
                                const conExtra = {};
                                const optionSelectCon = catalogoCon.find(e => a.valor == e[a.conValue]);
                                if (!!optionSelectCon && optionSelectCon !== -1) {
                                    arrCampos[a.id]['vs'] = optionSelectCon[a.conLabel];
                                    for (let keyOption in optionSelectCon) {
                                        if (a.conExtra.includes(keyOption)) {
                                            conExtra[keyOption] = optionSelectCon[keyOption];
                                        }
                                    }
                                    arrCampos[a.id]['ve'] = conExtra;
                                }
                            }

                        }

                        arrCampos[a.id]['t'] = a.tipoCampo;
                        arrCampos[a.id]['v'] = a.valor;

                        if (a.tipoCampo === 'signature') {

                            if (typeof self.$refs['signature_' + a.id] !== 'undefined' && typeof self.$refs['signature_' + a.id][0] !== 'undefined' && typeof self.$refs['signature_' + a.id][0].undoSignature === 'function') {
                                const {isEmpty, data} = self.$refs['signature_' + a.id][0].saveSignature();
                                if (!isEmpty) {
                                    arrCampos[a.id]['v'] = data;
                                    a.valor = data;
                                }
                            }
                        }

                        const valoresCampo = [];
                        let selected = false;
                        if (a.tipoCampo === 'checkbox' || a.tipoCampo === 'multiselect') {
                            arrCampos[a.id]['v'] = [];

                            if (typeof a.catalogoId !== "undefined") {
                                arrCampos[a.id]['v'] = a.valor;
                            }
                        }

                        // console.log(a);
                        if (a.requerido && (a.valor === '' || !a.valor)) {
                            if (typeof self.camposCumplidores[a.id] !== 'undefined' && self.camposCumplidores[a.id]) {
                                a.requeridoError = true;
                                requeridosFail = true;
                                if (parseInt(keySeccion) === parseInt(self.currentTabIndex)) {
                                    requeridosFailInSection = true;
                                }
                            }
                        }
                        else {
                            a.requeridoError = false;
                        }
                    });
                })

            }

            if (typeof self.flujoActivo.ocrOptions !== 'undefined') {
                for (const ocropt in self.flujoActivo.ocrOptions) {
                    const a = self.flujoActivo.ocrOptions[ocropt];
                    if (typeof arrCampos[a.id] === 'undefined') {
                        arrCampos[a.id] = {};
                    }
                    arrCampos[a.id]['t'] = a.tipoCampo;
                    arrCampos[a.id]['v'] = a.valor;
                }
            }

            /*
            if (typeof self.flujoActivo.ocrOptionsTables !== 'undefined'){
                const tables = self.flujoActivo.ocrOptionsTables;
                for (const table in tables) {
                    for (const rowKey in tables[table]['data']) {
                        const row = tables[table]['data'][rowKey];
                        for (const field in row) {
                            const a = row[field];
                            if (typeof arrCampos[a.id] === 'undefined') {
                                arrCampos[a.id] = {};
                            }
                            arrCampos[a.id]['t'] = a.tipoCampo;
                            arrCampos[a.id]['v'] = a.valor;
                        }
                    }
                }
            }
            */

            //Tables Ocr
            if (typeof self.flujoActivo.ocrData !== 'undefined') {
                const tables = self.flujoActivo.ocrData;
                for (const table in tables) {
                    for (const rowKey in tables[table]) {
                        const row = tables[table][rowKey];
                        for (const field in row) {
                            const a = row[field];
                            if (typeof arrCampos[a.id] === 'undefined') {
                                arrCampos[a.id] = {};
                            }
                            arrCampos[a.id]['t'] = a.tipoCampo;
                            arrCampos[a.id]['v'] = a.valor;
                        }
                    }
                }
            }

            return {
                c: arrCampos,
                sec: requeridosFailInSection,
                all: requeridosFail,
            };
        },
        continuarCotizacion(operacion, estado) {

            const self = this;
            if (!estado) {
                estado = false;
            }
            let requeridosFail = false;
            let requeridosFailInSection = this.evaluarRequeridos();
            let arrCampos = requeridosFailInSection.c;

            if (operacion === 'next') {

                if (requeridosFailInSection.sec) {
                    toolbox.alert('Debe llenar todos los campos requeridos', 'danger');
                    return false;
                }

                // this.campoCumpleCondiciones();

                if ((self.flujoActivo.formulario.secciones.length - 1) > self.currentTabIndex) {
                    self.removeRequiredClass();
                    for (let i = self.currentTabIndex + 1; i < self.flujoActivo.formulario.secciones.length; i++) {
                        if (self.flujoActivo.formulario.secciones[i].show) {
                            if (self.cambiarSeccion(i)) {
                                self.campoCumpleCondiciones();
                                return false;
                            }
                        }
                    }
                    ;
                }

                /* let conteoSeccionesShow = 0;
                 this.flujoActivo.formulario.secciones.forEach(function (a) {
                     if (a.show) {
                         conteoSeccionesShow++;
                     }
                 });

                 if (conteoSeccionesShow > (this.currentTabIndex + 1)) {
                     self.removeRequiredClass();
                     const tmpNext = this.currentTabIndex + 1;
                     this.cambiarSeccion(tmpNext, true);
                     return false;
                 } */
            }

            /*console.log('enviar')
            return false;*/

            if (operacion === 'prev') {
                this.currentTabIndex = 0;
            }

            const cambiarEstado = function () {
                toolbox.doAjax('POST', 'tareas/cambiar-estado' + (self.isPublic ? '/public' : ''), {
                    token: self.cToken,
                    paso: operacion,
                    seccionKey: self.currentTabIndex,
                    campos: arrCampos,
                    estado: estado,
                }, function (response) {
                    toolbox.alert(response.msg);
                    window.scrollTo({top: 0, behavior: 'smooth'});
                    self.getFlujo();
                }, function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
            }

            if (estado === 'finalizada') {
                toolbox.confirm('Si finaliza el formulario no podrá volver a editarlo', function () {
                    cambiarEstado();
                })
            }
            else {
                if (estado === 'guardada') {
                    self.showCotizacion = false;
                }
                else {
                    /*  if (requeridosFailInSection.all) {
                          toolbox.alert('Existen secciones sin revisar, para continuar revise todas las secciones', 'danger');
                          return false;
                      } */
                    cambiarEstado();
                }
            }
        },
        execRegex(regex, valor) {
            const arr = regex.exec(valor);
            if (arr) {
                return arr;
            }
            else {
                return false
            }
        },
        campoCumpleCondiciones(inputField) {

            const self = this;
            self.setSavedValuesForm(false);

            if (typeof this.flujoActivo.formulario.secciones !== 'undefined' && typeof this.flujoActivo.formulario.secciones[this.currentTabIndex] !== 'undefined') {

                this.flujoActivo.formulario.secciones.forEach(function (seccion, seccionKey) {

                    if (seccion.condiciones.length > 0 && !seccion.condiciones[0].campoId) {
                        self.flujoActivo.formulario.secciones[seccionKey].show = true;
                    }

                    const valoresRepetibles = {};

                    self.flujoActivo.formulario.secciones[seccionKey].campos.forEach(function (a, b) {
                        self.camposValores[a.id] = ((a.valor) ? a.valor.toString() : '');

                        // validación de catálogos, si dependen otros depués de el
                        if (typeof a.catFId !== 'undefined' && a.catFId !== '' && a.catFId) {
                            a.catFId = a.catFId.toString();
                            if (a.catFId !== '') {
                                self.catalogosCheckDepends[a.catFId] = a.catFId;
                            }
                        }

                        // si es repetible
                        if (typeof a.repetible !== 'undefined' && typeof a.repetibleId !== 'undefined' && a.repetible && a.repetibleId) {

                            let keyTmp = 'first_row';
                            if (typeof a.repetibleKey !== 'undefined' && a.repetibleKey) {
                                keyTmp = a.repetibleKey;
                            }
                            if (typeof valoresRepetibles[a.repetibleId] === 'undefined') {
                                valoresRepetibles[a.repetibleId] = {};
                            }
                            if (typeof valoresRepetibles[a.repetibleId][keyTmp]  === 'undefined') {
                                valoresRepetibles[a.repetibleId][keyTmp]  = {};
                            }
                            // si es repetible
                            let keyCampo = a.id;
                            if (a.id.indexOf("|") !== -1) {
                                keyCampo = keyCampo.substring(0, keyCampo.indexOf("|"));
                            }
                            valoresRepetibles[a.repetibleId][keyTmp][keyCampo] = a.valor;
                        }
                    })

                    const valoresRepetiblesString = JSON.stringify(valoresRepetibles);

                    self.flujoActivo.formulario.secciones[seccionKey].campos.forEach(function (a, b) {

                        self.camposCumplidores[a.id] = true;

                        // Validación de valores
                        if (a.tipoCampo === 'number' && parseFloat(a.longitudMax) !== 0) {
                            if (parseFloat(a.valor) > parseFloat(a.longitudMax)) {
                                a.valor = a.longitudMax;
                                toolbox.alert('Valor máximo ' + a.longitudMax, 'danger');
                            }
                            else {
                                if (parseFloat(a.valor) < parseFloat(a.longitudMin)) {
                                    a.valor = a.longitudMin;
                                    toolbox.alert('Valor mínimo ' + a.longitudMin, 'danger');
                                }
                            }
                        }

                        if (a.tipoCampo === 'geoposition') {

                            const geoPos = a.valor.split(',');
                            if (typeof a.geoPos === 'undefined') {
                                a.geoPos = {};
                            }

                            if (!a.geoPos.rpt) {
                                a.geoPos.lat = parseFloat(geoPos[0] || 0);
                                a.geoPos.lng = parseFloat(geoPos[1] || 0);
                                a.geoPos.rpt = true;

                                self.serviceRC('loadGeopos', a.valor);
                            }
                        }

                        // cálculo de repetibles
                        let repetiblesList = [];
                        /*Object.keys(self.camposValores).map(function (key) {
                            valorCalculadoOpt = valorCalculadoOpt.replaceAll("{{" + key + "}}", self.camposValores[key]);
                        })*/
                        /*console.log(self.camposValores)
                        console.log(a.repetible)
                        console.log(a.repetibleId)*/

                        if (typeof a.valorCalculado !== 'undefined' && a.valorCalculado) {

                            let valorCalculadoOpt = (a.valorCalculado !== '') ? a.valorCalculado : '';

                            // reemplaza repetibles

                            valorCalculadoOpt = valorCalculadoOpt.replaceAll("{{ALL_REP_FIELDS}}", valoresRepetiblesString);

                            Object.keys(self.camposValores).map(function (key) {
                                valorCalculadoOpt = valorCalculadoOpt.replaceAll("{{" + key + "}}", self.camposValores[key]);
                            })

                            /*if (typeof self.userVars !== 'undefined') {
                                self.userVars.forEach(function (uvar) {
                                    valorCalculadoOpt = valorCalculadoOpt.replaceAll("{{"+uvar.nombre+"}}", uvar.valor);
                                })
                            }*/

                            // Funciones
                            let hasDate = false;
                            let valorCalculado = '';

                            // FN.EDAD
                            let regex = self.execRegex(/FN.EDAD\((.*)\)/g, valorCalculadoOpt);
                            if (regex) {
                                let data = (regex[1]) ? regex[1] : '';
                                data = data.replaceAll('"', '').replaceAll("'", '').split(',');
                                const date = (data[0]) ? data[0] : false;
                                const formatInput = (data[1]) ? data[1] : false;
                                let value = '';
                                if (date && formatInput) {
                                    value = dayjs(date, formatInput);
                                    value = dayjs().diff(value, 'year', false)
                                }
                                valorCalculadoOpt = valorCalculadoOpt.replace(/FN.EDAD\((.*)\)/g, value);
                            }

                            // FN.SUMARDIAS
                            regex = self.execRegex(/FN.SUMARDIAS\((.*)\)/g, valorCalculadoOpt);
                            if (regex) {
                                let data = (regex[1]) ? regex[1] : '';
                                data = data.replaceAll('"', '').replaceAll("'", '').split(',');
                                const date = (data[0]) ? data[0] : false;
                                const formatInput = (data[1]) ? data[1] : false;
                                const formatOutput = (data[2]) ? data[2] : 0;
                                const dias = (data[3]) ? data[3] : 0;
                                let value = '';
                                if (date && formatInput && dias) {
                                    value = dayjs(date, formatInput).add(parseInt(dias), 'day');
                                    value = value.format(formatOutput);
                                }
                                valorCalculadoOpt = valorCalculadoOpt.replace(/FN.SUMARDIAS\((.*)\)/g, value);
                                hasDate = true;
                            }

                            valorCalculado = valorCalculadoOpt;

                            //console.log(valorCalculadoOpt);
                            if (!hasDate && valorCalculadoOpt !== '') {
                                try {
                                    valorCalculado = new Function('return ' + valorCalculadoOpt)();
                                } catch (e) {
                                    console.log('Error al realizar campo calculado' + e);
                                }
                            }

                            if (!valorCalculado) {
                                valorCalculado = '';
                            }

                            // reemplazo el valor
                            self.flujoActivo.formulario.secciones[seccionKey].campos[b].valor = valorCalculado.toString();
                            self.camposValores[a.id] = valorCalculado.toString();
                        }

                        if (typeof a.dependOn !== 'undefined') {
                            if (typeof a.dependOn[0] !== 'undefined' && typeof a.dependOn[0].campoId !== 'undefined' && a.dependOn[0].campoId) {
                                self.camposCumplidores[a.id] = false;
                            }
                        }

                        // valor de url
                        if (typeof self.hashVars[a.id] !== 'undefined' && self.hashVars[a.id] !== '') {
                            self.camposValores[a.id] = self.hashVars[a.id];
                            a.valor = self.hashVars[a.id];
                        }
                    })

                    // JS POST
                    self.flujoActivo.formulario.secciones[seccionKey].campos.forEach(function (a, b) {
                        if (inputField && inputField.id === a.id) {
                            // jsPost
                            let jsTmp = '';
                            if (typeof a.jsPost !== 'undefined' && a.jsPost && a.jsPost !== '') {
                                jsTmp = a.jsPost;
                                Object.keys(self.camposValores).map(function (key) {
                                    jsTmp = jsTmp.replaceAll("{{" + key + "}}", self.camposValores[key]);
                                })
                                if (jsTmp !== '') {
                                    try {
                                        const tmpJsRes = new Function('return (function(){' + jsTmp + '})();')();
                                        if (!tmpJsRes) {
                                            toolbox.alert("Error en validación de " + a.nombre, 'danger');
                                        }
                                    } catch (e) {
                                        console.log('Error al evaluar JS post llenado' + e);
                                    }
                                }
                            }
                        }
                    })

                    self.flujoActivo.formulario.secciones[seccionKey].campos.forEach(function (a, b) {
                        if (typeof a.dependOn !== 'undefined') {
                            self.camposCumplidores[a.id] = true;
                            a.dependOn.forEach(function (item) {
                                if (item.campoId) {
                                    if (typeof self.camposValores[item.campoId] !== 'undefined') {
                                        if (item.campoIs === '=') {
                                            self.camposCumplidores[a.id] = self.camposCumplidores[a.id] && (self.camposValores[item.campoId] == item.campoValue);
                                        }
                                        else {
                                            if (item.campoIs === '<') {
                                                self.camposCumplidores[a.id] = self.camposCumplidores[a.id] && (parseFloat(self.camposValores[item.campoId]) < parseFloat(item.campoValue));
                                            }
                                            else {
                                                if (item.campoIs === '<=') {
                                                    self.camposCumplidores[a.id] = self.camposCumplidores[a.id] && (parseFloat(self.camposValores[item.campoId]) <= parseFloat(item.campoValue));
                                                }
                                                else {
                                                    if (item.campoIs === '>') {
                                                        self.camposCumplidores[a.id] = self.camposCumplidores[a.id] && (parseFloat(self.camposValores[item.campoId]) > parseFloat(item.campoValue));
                                                    }
                                                    else {
                                                        if (item.campoIs === '>=') {
                                                            self.camposCumplidores[a.id] = self.camposCumplidores[a.id] && (parseFloat(self.camposValores[item.campoId]) >= parseFloat(item.campoValue));
                                                        }
                                                        else {
                                                            if (item.campoIs === '<>') {
                                                                self.camposCumplidores[a.id] = self.camposCumplidores[a.id] && (self.camposValores[item.campoId] != item.campoValue);
                                                            }
                                                            else {
                                                                if (item.campoIs === 'like') {
                                                                    self.camposCumplidores[a.id] = self.camposCumplidores[a.id] && (self.camposValores[item.campoId].toLowerCase().includes(item.campoValue.toLowerCase()));
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            })
                        }
                    })
                })

                // validacion de secciones
                this.flujoActivo.formulario.secciones.forEach(function (seccion, seccionKey) {

                    if (typeof seccion.condiciones != 'undefined' && seccion.condiciones.length > 0) {
                        seccion.condiciones.forEach(function (item) {

                            if (item.campoId) {

                                if (typeof self.camposValores[item.campoId] !== 'undefined') {

                                    const tmpValue = self.camposValores[item.campoId].toString();
                                    item.value = item.value.toString();

                                    if (item.campoIs === '=') {
                                        self.flujoActivo.formulario.secciones[seccionKey].show = (tmpValue == item.value);
                                    }
                                    else {
                                        if (item.campoIs === '<') {
                                            self.flujoActivo.formulario.secciones[seccionKey].show = (parseFloat(tmpValue) < parseFloat(item.value));
                                        }
                                        else {
                                            if (item.campoIs === '<=') {
                                                self.flujoActivo.formulario.secciones[seccionKey].show = (parseFloat(tmpValue) <= parseFloat(item.value));
                                            }
                                            else {
                                                if (item.campoIs === '>') {
                                                    self.flujoActivo.formulario.secciones[seccionKey].show = (parseFloat(tmpValue) > parseFloat(item.value));
                                                }
                                                else {
                                                    if (item.campoIs === '>=') {
                                                        self.flujoActivo.formulario.secciones[seccionKey].show = (parseFloat(tmpValue) >= parseFloat(item.value));
                                                    }
                                                    else {
                                                        if (item.campoIs === '<>') {
                                                            self.flujoActivo.formulario.secciones[seccionKey].show = (tmpValue != item.value);
                                                        }
                                                        else {
                                                            if (item.campoIs === 'like') {
                                                                self.flujoActivo.formulario.secciones[seccionKey].show = (tmpValue.toLowerCase().includes(item.value.toLowerCase()));
                                                            }
                                                            else {
                                                                self.flujoActivo.formulario.secciones[seccionKey].show = false;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                else {
                                    self.flujoActivo.formulario.secciones[seccionKey].show = false;
                                }
                            }
                        })
                    }
                    else {
                        self.flujoActivo.formulario.secciones[seccionKey].show = true;
                    }
                })
            }

            self.flujoActivo.formulario.secciones.forEach(function (seccion, seccionKey) {
                self.flujoActivo.formulario.secciones[seccionKey].campos.forEach(function (a, b) {
                    // llamada a filtro si no viene input

                    if (!inputField && typeof self.catalogosCheckDepends[a.id] !== 'undefined' && a.valor !== '') {
                        self.filtrarCatalogos(a);
                    }
                })
            })

            if (inputField && typeof self.catalogosCheckDepends[inputField.id] !== 'undefined') {
                self.filtrarCatalogos(inputField);
            }
        },
        cambiarSeccion(seccion, recursive) {

            // console.log(seccion);

            if (!recursive) {
                recursive = false;
            }
            const self = this;
            const requeridosVal = this.evaluarRequeridos();

            const getNextSeccion = function (next) {

                let sectionTmp = next;
                if (typeof self.flujoActivo.formulario.secciones[sectionTmp] !== 'undefined') {
                    if (!self.flujoActivo.formulario.secciones[sectionTmp].show) {
                        sectionTmp = getNextSeccion(sectionTmp + 1);
                    }
                }
                return sectionTmp;
            }

            if (recursive) {
                seccion = getNextSeccion(seccion);
            }

            if (requeridosVal.sec) {
                toolbox.alert('Debe llenar todos los campos requeridos', 'danger');
                return false;
            }

            // Filtrar las secciones que cumplen con las condiciones
            this.campoCumpleCondiciones();

            const nextSection = (typeof this.flujoActivo.formulario.secciones[seccion] !== 'undefined') ? this.flujoActivo.formulario.secciones[seccion] : false;

            if (!nextSection) {
                return false;
            }

            // si está visible
            if (nextSection.show) {
                this.currentTabIndex = seccion;
                this.$refs.wizzardSteps.slideTo(this.currentTabIndex);
                return true;
            }
            else {
                return false;
            }
        },
        cambiarSeccionSlide(seccion) {
            const self = this;
            const requeridosVal = this.evaluarRequeridos();
            if (requeridosVal.sec) {
                toolbox.alert('Debe llenar todos los campos requeridos', 'danger');
                return false;
            }

            // Filtrar las secciones que cumplen con las condiciones
            this.campoCumpleCondiciones();

            const nextSection = (typeof this.flujoActivo.formulario.secciones[seccion] !== 'undefined') ? this.flujoActivo.formulario.secciones[seccion] : false;

            if (!nextSection) {
                return false;
            }

            // si está visible
            if (nextSection.show) {
                this.currentTabIndex = seccion;
                this.$refs.wizzardSteps.slideTo(this.currentTabIndex);
                return true;
            }
            else {
                return false;
            }
        },

        // archivos adjuntos
        handleUpload(file, campoId, load, error, progress, refreshFields) {
            if (file) {

                const self = this;
                if (!refreshFields) refreshFields = false;

                // creo la data
                const formData = new FormData();
                formData.append('file', file);
                formData.append('seccionKey', this.currentTabIndex);
                formData.append('token', self.cToken);
                formData.append('campoId', campoId);

                toolbox.doAjax('FILE', 'tareas/upload-file' + (self.isPublic ? '/public' : ''), formData,
                    function (response) {

                        if (response.status) {
                            self.flujoActivo.formulario.secciones[self.currentTabIndex].campos.forEach(function (a, b) {
                                if (campoId === a.id) {
                                    // console.log(response.data)
                                    self.flujoActivo.formulario.secciones[self.currentTabIndex].campos[b].valor = '__SKIP__FILE__';
                                    self.archivosTemporales[campoId] = (typeof response.data.key !== 'undefined' ? response.data.key : false);
                                }
                            })

                            if (refreshFields) {
                                self.verifyModal = true;
                                self.getFlujo();
                            }
                        }
                        load(response?.data?.id);
                        self.previewAdjunto();
                    },
                    function (response) {
                        error('Error en subida de archivo');
                        toolbox.alert(response.msg, 'danger');
                    })
            }
            else {
                // Indicar que no se ha seleccionado ningún archivo
                error('No se ha seleccionado ningún archivo');
            }
        },
        previewAdjunto() {
            const self = this;
            if (!this.isPublic) {

                toolbox.doAjax('POST', 'tareas/file-get-preview', {
                    token: self.cToken,
                    seccionKey: self.currentTabIndex,
                }, function (response) {
                    self.previewFiles = response.data;
                    Object.keys(self.previewFiles).map(function (key) {
                        if (self.previewFiles[key].url !== '' && self.previewFiles[key].field && typeof self.allFieldsData[self.previewFiles[key].field] !== 'undefined') {

                            // se guarda el download
                            self.previewFiles[key].download = self.previewFiles[key].url;

                            if (self.previewFiles[key].type === 'signature') {
                                self.signatureData[self.previewFiles[key].field] = self.previewFiles[key].url;
                            }
                            else {
                                if (self.previewFiles[key].type === 'image') {
                                }
                                else {
                                    if (self.previewFiles[key].type === 'pdf') {
                                        self.previewFiles[key].url = 'filetypes/PDF.png';
                                    }
                                    else {
                                        if (self.previewFiles[key].type === 'DOCX' || self.previewFiles[key].type === 'DOC') {
                                            self.previewFiles[key].url = 'filetypes/DOCX.png';
                                        }
                                    }
                                }
                            }
                        }
                    })

                    self.setSavedValuesForm(true);
                    self.campoCumpleCondiciones();

                    if (!self.lightboxAttachment) {
                        self.lightboxAttachment = new PhotoSwipeLightbox({
                            gallery: '#fileAttachGallery',
                            children: 'a',
                            pswpModule: () => import('photoswipe'),
                            showHideAnimationType: 'none',
                            history: false,
                            focus: false,
                            showAnimationDuration: 0,
                            hideAnimationDuration: 0,
                            zoomEl: true,
                            clickToCloseNonZoomable: false,
                            maxSpreadZoom: 6,
                            pinchToClose: false,
                            initialZoomLevel: 1,
                            maxZoomLevel: 6,
                            secondaryZoomLevel: (zoomLevelObject) => {
                                return 6;
                            }
                        });
                        self.lightboxAttachment.on('uiRegister', function () {
                            self.lightboxAttachment.pswp.ui.registerElement({
                                name: 'download-button',
                                order: 8,
                                isButton: true,
                                tagName: 'a',

                                // SVG with outline
                                html: {
                                    isCustomSVG: true,
                                    inner: '<path d="M20.5 14.3 17.1 18V10h-2.2v7.9l-3.4-3.6L10 16l6 6.1 6-6.1ZM23 23H9v2h14Z" id="pswp__icn-download"/>',
                                    outlineID: 'pswp__icn-download'
                                },
                                onInit: (el, pswp) => {
                                    el.setAttribute('download', '');
                                    el.setAttribute('target', '_blank');
                                    el.setAttribute('rel', 'noopener');
                                    pswp.on('change', () => {
                                        el.href = pswp._initialItemData.element.getAttribute('data-download');
                                    });
                                }
                            });
                        });
                        self.lightboxAttachment.init();
                    }
                }, function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
            }
            else {
                self.campoCumpleCondiciones();
            }
        },
        openPreview(file) {
            if (file.type === 'image') {
                this.showImagePreview = true;
                this.imagePreviewTmp = file;
                this.imageOcrTmp = file;
            }
            else {
                window.open(file.url);
            }
        },
        openWindow(url) {
            window.open(url);
        },
        handleRevert(uniqueFileId, load, error) {
            if (uniqueFileId) {

                const self = this;

                // creo la data
                const formData = new FormData();
                formData.append('id', uniqueFileId);

                toolbox.doAjax('FILE', 'tareas/revert-file', formData,
                    function (response) {
                        if (response.status) {
                            load();
                            self.previewAdjunto();
                        }
                    },
                    function (response) {
                        error('Error al remover archivo');
                        toolbox.alert(response.msg, 'danger');
                    })
            }
            else {
                // Indicar que no se ha seleccionado ningún archivo
                error('No se ha seleccionado ningún archivo');
            }
        },

        savedAudioFile(data) {
            const self = this;
            self.flujoActivo.formulario.secciones[self.currentTabIndex].campos[data.fieldKey].urlTmp = data.response.data.key;
            self.flujoActivo.formulario.secciones[self.currentTabIndex].campos[data.fieldKey].exd = data.response.data.ed;
        },
        /*getTranscriptionAudioFile(campoId, fieldKey) {
            const self = this;

            toolbox.doAjax('POST', 'tareas/audio-transcrip-check', {
                    campoId: campoId,
                    token: self.cToken,
                },
                function (response) {
                    self.flujoActivo.formulario.secciones[self.currentTabIndex].campos[fieldKey].exd = response.data;
                    self.flujoActivo.formulario.secciones[self.currentTabIndex].campos[fieldKey].exdTND = response.msg;
                    //toolbox.alert(response.msg, 'success');
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },*/

        // Ver progresión
        verProgresion() {

            const self = this;
            toolbox.doAjax('POST', 'tareas/get-progression', {
                token: self.cToken,
            }, function (response) {
                self.showProgresion = true;
                self.progresion = response.data;
            }, function (response) {
                toolbox.alert(response.msg, 'danger');
            })
        },
        resetSignature(ref, reset, currentTabIndex, keyInput) {
            if (!reset) {
                reset = false;
            }
            if (typeof this.$refs[ref] !== 'undefined' && typeof this.$refs[ref][0] !== 'undefined' && typeof this.$refs[ref][0].undoSignature === 'function') {
                this.$refs[ref][0].undoSignature();
                if (reset) {
                    this.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor = '';
                }
            }
        },

        // Rejecutar paso
        rerunstep() {
            const self = this;
            if (!this.isPublic) {
                toolbox.confirm('Se volverá a procesar este flujo, se evaluarán condicionales y se ejecutarán salidas, ¿desea continuar?', function () {
                    toolbox.doAjax('POST', 'tareas/re-run-step', {
                        token: self.cToken,
                    }, function (response) {
                        self.getFlujo();
                    }, function (response) {
                        toolbox.alert(response.msg, 'danger');
                    })
                })
            }
        },

        // catalogos
        filtrarCatalogos(inputID) {

            if (!inputID) {
                inputID = {};
            }
            //console.log(inputID.valor);

            const self = this;
            toolbox.doAjax('POST', 'tareas/calcular-catalogo/public', {
                'token': self.cToken,
                'factual': self.flujoActivo.nodoId ?? false,
                'depends': (typeof inputID.id !== 'undefined') ? inputID.id : '',
                'value': (typeof inputID.valor !== 'undefined') ? inputID.valor : '',
            }, function (response) {
                if (response.status) {
                    if (typeof inputID.id === 'undefined' || (typeof inputID.id !== 'undefined' && !inputID)) {
                        self.catalogos = response.data;
                    }
                    else {
                        Object.keys(response.data).map(function (a) {
                            if (inputID.id !== a) {
                                self.catalogos[a] = response.data[a];
                            }
                        })
                    }
                }
                else {
                    toolbox.alert('Ha ocurrido un error obteniendo el producto', 'danger');
                }
            }, function (response) {
                toolbox.alert(response.msg, 'danger');
            })
        },
        calcularCatalogosOcrTablas(inputID = {}, dataRow = [], nameTable, keyTr, keyItem) {
            const self = this;
            toolbox.doAjax('POST', 'tareas/calcular-catalogo-tabla-ocr', {
                token: self.cToken,
                factual: self.flujoActivo.nodoId ?? false,
                depends: (typeof inputID.id !== 'undefined') ? inputID.id : '',
                value: (typeof inputID.valor !== 'undefined') ? inputID.valor : '',
                row: inputID.row,
                tokenId: nameTable,
                dataRow,
            }, function (response) {
                if (response.status) {
                    self.flujoActivo.ocrData[nameTable][keyTr][keyItem].options = Object.values(response.data)[0];
                }
                else {
                    toolbox.alert('Ha ocurrido un error obteniendo el producto', 'danger');
                }
            }, function (response) {
                toolbox.alert(response.msg, 'danger');
            })
        },
        obtenerItemsPorCatalogo(inputId) {

            if (typeof this.catalogos[inputId] !== 'undefined') {
                return this.catalogos[inputId];
            }
            else {
                return [];
            }
        },
        obtenerItemsPorCatalogoMS(inputId, catalogoLabel, catalogoValue) {

            // si es repetible
            if (typeof inputId.indexOf === 'function' && inputId.indexOf("|") !== -1) {
                inputId = inputId.substring(0, inputId.indexOf("|"));
            }

            const tmp = [];
            const self = this;
            if (typeof self.catalogos[inputId] !== 'undefined') {
                self.catalogos[inputId].forEach(function (a) {
                    tmp.push({label: a[catalogoLabel], value: a[catalogoValue]});
                })
                return tmp;
            }
            else {
                return [];
            }
        },

        obtenerItemsPorCatalogoMSOcr(options, catalogoLabel, catalogoValue) {
            const tmp = [];
            options.forEach(function (a) {
                tmp.push({label: a[catalogoLabel], value: a[catalogoValue]});
            })
            return tmp;
        },

        goToProduct() {
            this.$router.push('/f/' + this.pToken + '/view');
            setTimeout(function () {
                location.reload();
            }, 500);
        },

        revivirCotizacion() {
            const self = this;
            if (!this.isPublic) {
                toolbox.confirm('Se creará un nuevo formulario, ¿desea continuar?', function () {
                    toolbox.doAjax('POST', 'tareas/revivir-cotizacion', {
                        token: self.cToken,
                    }, function (response) {
                        self.$router.push('/flow/' + self.pToken + '/' + response.data.token);
                        setTimeout(function () {
                            location.reload();
                        }, 800);
                    }, function (response) {
                        toolbox.alert(response.msg, 'danger');
                    })
                })
            }
        },

        // Comentarios
        getComentarios() {
            const self = this;
            toolbox.doAjax('POST', 'tareas/comment/get', {
                token: self.cToken,
            }, function (response) {
                self.showComentariosBar = true;
                self.comentarios = response.data;
            }, function (response) {
                toolbox.alert(response.msg, 'danger');
            })
        },
        sendComentario() {
            const self = this;

            if (this.isPublic) {
                self.comentarioAcceso = 'publico';
            }

            toolbox.doAjax('POST', 'tareas/comment/save', {
                token: self.cToken,
                comment: self.comentarioTmp,
                comentarioAcceso: self.comentarioAcceso,
            }, function (response) {
                self.comentarioTmp = '';
                self.getComentarios()
            }, function (response) {
                toolbox.alert(response.msg, 'danger');
            })
        },

        // save change onblur
        saveWhenOnBlur(input, vs, repetibleRm, forceRepetibleKey, presaveIds) {
            const self = this;
            const {id, showInReports, valor, tipoCampo, repetibleId, repetibleKey} = input;
            const repetibleKeyTmp = (forceRepetibleKey) ? forceRepetibleKey : repetibleKey;
            const campo = {
                'v': valor,
                't': tipoCampo,
                'r': repetibleId,
                'rK': repetibleKeyTmp,
                'rR': repetibleRm,
                'pId': presaveIds,
            };
            //console.log(input);
            if (vs) {
                campo['vs'] = vs;
            }
            toolbox.doAjax('POST', 'tareas/save-field-on-blur' + (self.isPublic ? '/public' : ''), {
                token: self.cToken,
                seccionKey: self.currentTabIndex,
                campoKey: id,
                campo,
                showInReports
            }, function (response) {
                let verifyCatFillBy = false;
                self.flujoActivo.formulario.secciones[self.currentTabIndex].campos.forEach(function (a, b) {
                    if (a.fillBy === 'con' && !!a.fillByCon) {
                        verifyCatFillBy = true;
                    }
                });
                if (verifyCatFillBy) {
                    self.filtrarCatalogos();
                }
            }, function (response) {
            }, false, false)
        },
        copyLink(link) {
            const linkTmp = config.appUrl + link;
            toolbox.copyToClipboard(linkTmp);
        },
        async saveValueMultiSelect(input) {
            const self = this;
            await self.campoCumpleCondiciones(input);
            self.saveWhenOnBlur(input);
        },
        handleInputTags(event, input) {
            input.valor = event
            this.saveWhenOnBlur(input);
        },

        // Check Select
        selectedMultiValue(input, value, keyInput, currentTabIndex) {
            const self = this;

            if (!self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor) {
                self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor = []
            }
            ;
            if (self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor.includes(value)) {
                self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor =
                    self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor.filter(e => e !== value)
            }
            else {
                self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor.push(value)
            }

            self.campoCumpleCondiciones(input);
        },

        saveSignature(keyInput, input) {
            const self = this;
            if (input.tipoCampo === 'signature') {
                if (typeof self.$refs['signature_' + input.id] !== 'undefined' && typeof self.$refs['signature_' + input.id][0] !== 'undefined' && typeof self.$refs['signature_' + input.id][0].undoSignature === 'function') {
                    const {isEmpty, data} = self.$refs['signature_' + input.id][0].saveSignature();
                    if (!isEmpty) {
                        //  arrCampos[input.id]['v'] = data;
                        input.valor = data;
                        self.flujoActivo.formulario.secciones[self.currentTabIndex].campos[keyInput].valor = data;
                        const {id, showInReports, valor, tipoCampo} = input;
                        const campo = {
                            'v': valor,
                            't': tipoCampo
                        };
                        toolbox.doAjax('POST', 'tareas/save-field-on-blur' + (self.isPublic ? '/public' : ''), {
                            token: self.cToken,
                            seccionKey: self.currentTabIndex,
                            campoKey: id,
                            campo,
                            showInReports
                        }, function (response) {
                            self.signatureData[input.id] = self.flujoActivo.formulario.secciones[self.currentTabIndex].campos[keyInput].valor;
                        }, function (response) {
                        }, false, false)
                    }
                }
            }
        },

        changeScalePreviewOcr(type) {
            const self = this;
            if (type === 'add') {
                self.scaleOcr += 10
            }
            else {
                self.scaleOcr -= 10
            }
        },

        consumeServiceForField({proceso, id}, dataRow = false) {
            const self = this;
            const actProceso = {...proceso};
            if (dataRow) {
                const {nameTable, keytr} = dataRow;
                const row = self.flujoActivo.ocrOptionsTables[nameTable].data[keytr];
                for (let procc in actProceso) {
                    Object.keys(row).map(function (key) {
                        if (typeof actProceso[procc] === 'string') {
                            actProceso[procc] = actProceso[procc].replaceAll("{{" + row[key]['id'] + "}}", row[key]['valor']);
                        }
                    })
                }
                ;
            }

            toolbox.doAjax('POST', 'tareas/calcular-campo-proceso' + (self.isPublic ? '/public' : ''), {
                proceso: actProceso,
                token: self.cToken,
            }, function (response) {
                let optionsProcess = JSON.stringify(response.data);
                if (!!actProceso && !!actProceso.configuracionS[0]) {
                    let valorCalculadoOpt = actProceso.configuracionS[0];
                    valorCalculadoOpt = valorCalculadoOpt.replaceAll("{{" + actProceso.identificadorWs + "}}", optionsProcess);
                    Object.keys(self.camposValores).map(function (key) {
                        valorCalculadoOpt = valorCalculadoOpt.replaceAll("{{" + key + "}}", self.camposValores[key]);
                    })
                    if (valorCalculadoOpt !== '') {
                        try {
                            optionsProcess = new Function('return ' + valorCalculadoOpt)();
                        } catch (e) {
                            console.log('Error al realizar campo calculado' + e);
                        }
                    }
                }
                ;
                if (typeof optionsProcess === 'string') {
                    self.processCatalog[id] = [{
                        texto: optionsProcess,
                        valor: optionsProcess
                    }];
                }
                else {
                    let newoptions = [];
                    for (let element of optionsProcess) {
                        if (typeof element === 'string') {
                            newoptions.push({texto: element, valor: element});
                        }
                        else {
                            newoptions.push(element);
                        }
                    }
                    self.processCatalog[id] = newoptions;
                }
            }, function (response) {
                toolbox.alert(response.msg, 'danger');
            })
        },

        saveOptionsOcrTable(item, tokenId) {
            const self = this;
            const valor = item.valor;
            const option = item.options.find(e => e[item.conValue] == valor);
            if (!option) {
                return false;
            }
            toolbox.doAjax('POST', 'tareas/save-field-on-ocr-table', {
                token: self.cToken,
                row: item.row,
                tokenId,
                option,
                campo: item.id,
            }, function (response) {
                self.saveWhenOnBlur(item);
            }, function (response) {
                toolbox.alert(response.msg, 'danger');
            })
        },

        getGeoPosition(input) {

            const self = this;
            const fieldID = input.id;

            if (navigator.geolocation) {
                let mapRef = 'mapGeo_' + fieldID;
                let markerRef = 'mapMarkerGeo_' + fieldID;

                navigator.geolocation.getCurrentPosition(function (position) {
                    if (typeof input.geoPos === 'undefined') {
                        input.geoPos = {};
                    }
                    input.geoPos.lat = position.coords.latitude;
                    input.geoPos.lng = position.coords.longitude;
                    input.valor = input.geoPos.lat + "," + input.geoPos.lng;

                    if (typeof self.$refs[mapRef] !== 'undefined' && typeof self.$refs[mapRef][0] !== 'undefined') {
                        self.$refs[mapRef][0].map.setCenter(input.geoPos)
                    }
                    if (typeof self.$refs[markerRef] !== 'undefined' && typeof self.$refs[markerRef][0] !== 'undefined') {
                        self.$refs[markerRef][0].marker.setOptions({position: input.geoPos})
                    }
                    //console.log(input)
                    self.saveWhenOnBlur(input, false, false, false, false);
                    self.serviceRC('loadGeopos', input.value)
                });
            }
            else {
                toolbox.alert("Este navegador no es compatible con geoposicionamiento");
            }
        },

        serviceRC(type, data) {
            const self = this;
            toolbox.doAjax('POST', 's/r/c', {
                    t: self.cToken,
                    ty: type,
                    d: data,
                },
                function (response) {
                },
                function (response) {
                    console.log('Error loading SRC');
                })
        },

        // Audio
        audioFieldCallback(data) {
            console.debug(data)
        },

        // repetibles
        addFieldsGroup(input, keyInput, currentTabIndex){
            const self = this;

            // const valor = (!input.valor || Number.isNaN(Number(input.valor))? 1 : Number(input.valor)) + 1;
            const valor = (Math.random() + 1).toString(36).substring(7);
            let fieldsGroup = self.flujoActivo.formulario.secciones[currentTabIndex].campos.filter(camp => typeof camp.repetible !== 'undefined' && camp.repetible && camp.repetibleId && camp.repetibleId !== '' && camp.repetibleId === input.repetibleId && !camp.repetibleKey);

            const presaveIds = [];

            let fieldsGroupAdap =   fieldsGroup.map(campGroup => {
                let tokenKey = (Math.random() + 1).toString(36).substring(7);
                let campGroupNew = {...campGroup};
                campGroupNew['id'] = campGroup['id'] + '|' + valor;
                campGroupNew['valor'] = '';
                campGroupNew['repetibleId'] = campGroup['repetibleId'];
                campGroupNew['repetibleKey'] = valor;
                presaveIds.push(campGroupNew['id']);
                /*if(fieldsGall.includes(campGroupNew['catFId'])){
                    campGroupNew['catFId'] = `${campGroup['catFId']}|${valor}`;
                }
                if(Array.isArray(campGroup['dependOn'])){
                    campGroupNew['dependOn'] = campGroup['dependOn'].map(c => {
                        //  dependOn: [{campoId: '', campoIs: '', campoValue: ''}],
                        let campoId = c.campoId;
                        if(!!c['campoId'] && fieldsGroup.some(camp => camp.id === c['campoId'])) campoId = `${campGroup['id']}|${valor}`;
                        return {...c, campoId};
                    });
                }
                for(let keyCamp in campGroup){
                    for(let idGroup of  fieldsGall){
                        if(typeof campGroup[keyCamp] !== 'string') continue;
                        campGroupNew[keyCamp] =  campGroupNew[keyCamp].replaceAll("{{"+idGroup+"}}", "{{"+`${campGroup['id']}|${valor}`+"}}" );
                    }
                }*/
                self.camposCumplidores[campGroupNew['id']] = true;
                return campGroupNew;
            });

            // console.log(fieldsGroupAdap);

            // self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor = valor;
            self.flujoActivo.formulario.secciones[currentTabIndex].campos.splice(keyInput + 1, 0, ...fieldsGroupAdap);
            self.saveWhenOnBlur(self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput], false, false, valor, presaveIds);
            self.campoCumpleCondiciones();
        },
        subFieldsGroup(input, keyInput, currentTabIndex){
            const self = this;
            // const valor = (!input.valor || Number.isNaN(Number(input.valor))? 1 : Number(input.valor));

            let allfields = self.flujoActivo.formulario.secciones[currentTabIndex].campos;
            //let fieldsGroupAdap = allfields.filter(camp => camp['repetibleId'] === input.repetibleId && camp['repetibleKey'] === input.repetibleKey ).map(e => e);
            // self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput].valor = 1;

            if (input.repetibleKey && input.repetibleKey !== '') {
                self.saveWhenOnBlur(self.flujoActivo.formulario.secciones[currentTabIndex].campos[keyInput], false, true);
                self.flujoActivo.formulario.secciones[currentTabIndex].campos = allfields.filter(a => (!a['repetibleId']) || (a['repetibleId'] && a['repetibleId'] === input.repetibleId && a['repetibleKey'] !== input.repetibleKey));
            }

            self.campoCumpleCondiciones();
        },

        // prueba de vida
        enableMetaMap() {
            const self = this;
            self.metaMapActive = true;

            const configuration = {
                clientId: "67c6052199631d0d8efc2fa1",
                flowId: "67c6052199631deb08fc2fa0",
            };

            self.metaMap = new MetamapVerification(configuration);

            self.metaMap.on('metamap:userStartedSdk', ({ detail }) => {
                console.log('started payload', detail)
            });
            self.metaMap.on('metamap:userFinishedSdk', ({ detail }) => {
                console.log('finished payload', detail)
            });
        },
        showMetaMap() {
            this.metaMap.start();
        },


        // firma electrónica avanzada
        firmaElectronicaAvanzada(file) {
            const self = this;

            if (file.signE === 'crear') {
                toolbox.confirm('Se iniciará un proceso de firma electrónica', function () {
                    toolbox.doAjax('POST', 'signature/start', {
                            t: self.cToken,
                            c: file.id,
                        },
                        function (response) {
                            self.previewAdjunto();
                            toolbox.alert(response.msg);
                        },
                        function (response) {
                            toolbox.alert(response.msg);
                        })
                })
            }
            else if (file.signE === 'creada') {
                toolbox.doAjax('POST', 'signature/validate', {
                        t: self.cToken,
                        i: file.signI,
                    },
                    function (response) {
                        toolbox.alert(response.msg);
                    },
                    function (response) {
                        toolbox.alert(response.msg);
                    })
            }
        },
        firmaElectronicaAvanzadaGetForUser() {
            const self = this;
            if (this.isPublic) {
                toolbox.doAjax('POST', 'signature/get-public', {
                    t: self.cToken,
                },
                function (response) {
                    self.firmaElectronicaAvanzadaItems = response.data;
                },
                function (response) {
                    // toolbox.alert(response.msg);
                })
            }
        },
    }
}
</script>
<style>
.vue-pdf-embed > div {
    margin-bottom: 20px;
    box-shadow: 0 2px 8px 4px rgba(0, 0, 0, 0.1);
}
</style>
